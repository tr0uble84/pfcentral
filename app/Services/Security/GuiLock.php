<?php

namespace App\Services\Security;

use App\Models\Firewall;
use App\Models\FirewallGuiLock;
use App\Services\PfSenseApiService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Locks a pfSense web interface to PFCentral (System → Security). Optional and per firewall.
 *
 * Before locking, the firewall lists who is connected to its web interface port: PFCentral's own connection must
 * come from one of the allowed addresses, or nothing is changed. After locking, PFCentral confirms right away that
 * it still reaches the firewall; if it can't, the firewall removes the lock by itself after 3 minutes.
 */
class GuiLock
{
    private const MARK = 'ACXGL:';
    private const DIR = '/root/.pfcentral';
    private const CHUNK = 900;
    private const PHP = '/usr/local/bin/php';

    /** Tests: fn (Firewall $fw, string $action, array $args): array */
    public static ?\Closure $fake = null;

    public function __construct(private Firewall $firewall)
    {
    }

    public function status(): array
    {
        $s = $this->call('status');
        $this->save(['locked' => (bool) $s['locked'], 'port' => (string) ($s['port'] ?? ''), 'allowed' => $s['allowed'] ?? [], 'error' => null]);
        return $s;
    }

    /** @return string[] addresses connected to the web interface port now */
    public function peers(): array
    {
        return array_values((array) ($this->call('peers')['peers'] ?? []));
    }

    /**
     * @param string[] $allowed
     * @return array the firewall's status after the lock
     */
    public function lock(array $allowed, string $by): array
    {
        $allowed = array_values(array_filter($allowed, fn ($a) => SecuritySettings::validAddress($a)));
        if (!$allowed) {
            throw new RuntimeException('Add PFCentral\'s address under System → Security first.');
        }
        $peers = $this->peers();
        $this->save(['seen' => $peers]);
        if (!array_filter($peers, fn ($ip) => self::covered($ip, $allowed))) {
            throw new RuntimeException('Not locked: PFCentral reaches this firewall from ' . ($peers ? implode(', ', $peers) : 'an address the firewall didn\'t report')
                . ', which isn\'t in the allowed addresses. Add the right one under System → Security, then lock again.');
        }
        $after = $this->call('lock', ['allowed' => $allowed, 'by' => $by]);
        try {
            $confirmed = $this->call('confirm'); // proves PFCentral still gets through
        } catch (\Throwable $e) {
            Log::warning('Web interface lock: PFCentral could not confirm; the firewall will undo the lock in 3 minutes', ['firewall' => $this->firewall->id, 'error' => $e->getMessage()]);
            $this->save(['locked' => true, 'allowed' => $allowed, 'changed_by' => $by, 'error' => 'PFCentral couldn\'t confirm the lock, so the firewall undoes it by itself in 3 minutes.']);
            throw new RuntimeException('Locked, but PFCentral couldn\'t reach the firewall afterwards, so the firewall will undo the lock by itself within 3 minutes.');
        }
        $this->save(['locked' => true, 'allowed' => $allowed, 'port' => (string) ($confirmed['port'] ?? ''), 'changed_by' => $by, 'error' => null]);
        Log::info('Web interface locked to PFCentral', ['firewall' => $this->firewall->id, 'by' => $by, 'allowed' => $allowed]);
        return $confirmed + $after;
    }

    public function unlock(string $by): array
    {
        $s = $this->call('unlock', ['by' => $by]);
        $this->save(['locked' => false, 'changed_by' => $by, 'error' => null]);
        Log::info('Web interface lock removed', ['firewall' => $this->firewall->id, 'by' => $by]);
        return $s;
    }

    /** Is the address inside one of the allowed addresses or networks? */
    public static function covered(string $ip, array $allowed): bool
    {
        foreach ($allowed as $a) {
            if (!str_contains($a, '/')) {
                if (strcasecmp(inet_ntop(inet_pton($a) ?: '') ?: $a, inet_ntop(inet_pton($ip) ?: '') ?: $ip) === 0) {
                    return true;
                }
                continue;
            }
            [$net, $bits] = explode('/', $a, 2);
            $n = @inet_pton($net);
            $i = @inet_pton($ip);
            if ($n === false || $i === false || strlen($n) !== strlen($i)) {
                continue;
            }
            $bits = (int) $bits;
            $bytes = intdiv($bits, 8);
            $rest = $bits % 8;
            if (substr($n, 0, $bytes) !== substr($i, 0, $bytes)) {
                continue;
            }
            if ($rest === 0 || ((ord($n[$bytes]) ^ ord($i[$bytes])) & (0xFF << (8 - $rest)) & 0xFF) === 0) {
                return true;
            }
        }
        return false;
    }

    private function save(array $values): void
    {
        FirewallGuiLock::updateOrCreate(['firewall_id' => $this->firewall->id], $values + ['checked_at' => now()]);
    }

    // ---- the helper script on the firewall (same transport as the pfBlockerNG helper) ----

    public function call(string $action, array $args = []): array
    {
        if (self::$fake) {
            $r = (self::$fake)($this->firewall, $action, $args);
        } else {
            if ($this->firewall->isOpnSense()) {
                throw new RuntimeException('The web interface lock is for pfSense firewalls.');
            }
            $this->ensureScript();
            $out = $this->run($action, $args);
            if (str_contains($out, 'Could not open input file')) {
                Cache::forget($this->cacheKey());
                $this->ensureScript();
                $out = $this->run($action, $args);
            }
            $r = $this->decode($out);
        }
        if (($r['ok'] ?? false) !== true) {
            throw new RuntimeException($r['error'] ?? 'The firewall didn\'t accept the request.');
        }
        return $r;
    }

    private function api(): PfSenseApiService
    {
        return (new PfSenseApiService($this->firewall))->setApiTimeout(60);
    }

    private function shell(string $command): string
    {
        if (strlen($command) > 1024) {
            throw new RuntimeException('Command too long for the firewall command prompt.');
        }
        $out = $this->api()->commandPrompt($command)['data']['output'] ?? '';
        return is_array($out) ? implode("\n", $out) : (string) $out;
    }

    private function run(string $action, array $args): string
    {
        $command = self::PHP . ' ' . $this->path() . ' ' . escapeshellarg($action);
        if ($args) {
            $payload = base64_encode(json_encode($args, JSON_UNESCAPED_SLASHES));
            if (strlen($payload) + strlen($command) > self::CHUNK) {
                $file = '/tmp/acx_a_' . Str::lower(Str::random(16));
                $this->upload($file, $payload);
                $command .= ' @' . $file;
            } else {
                $command .= ' ' . $payload;
            }
        }
        return $this->shell($command);
    }

    private function decode(string $output): array
    {
        if (!preg_match('/' . self::MARK . '([A-Za-z0-9+\/=]+)/', $output, $m)) {
            throw new RuntimeException('Unexpected answer from the firewall: ' . Str::limit(trim(preg_replace('/\s+/', ' ', $output)), 200));
        }
        $data = json_decode((string) base64_decode($m[1]), true);
        if (!is_array($data)) {
            throw new RuntimeException('The firewall sent an answer PFCentral couldn\'t read.');
        }
        return $data;
    }

    public static function script(): string
    {
        static $s = null;
        return $s ??= php_strip_whitespace(resource_path('firewall-scripts/guilock.php'));
    }

    private function path(): string
    {
        return self::DIR . '/guilock-' . substr(sha1(self::script()), 0, 12) . '.php';
    }

    private function cacheKey(): string
    {
        return 'guilock_script:' . $this->firewall->id . ':' . substr(sha1(self::script()), 0, 12);
    }

    private function upload(string $file, string $base64): void
    {
        foreach (str_split($base64, self::CHUNK) as $piece) {
            $this->shell("printf '%s' '" . $piece . "' >> " . $file);
        }
    }

    private function ensureScript(): void
    {
        if (Cache::get($this->cacheKey())) {
            return;
        }
        $path = $this->path();
        if (!str_contains($this->shell('test -f ' . $path . ' && echo ACXOK || echo ACXMISSING'), 'ACXOK')) {
            $tmp = '/tmp/acx_up_' . Str::lower(Str::random(16));
            $this->upload($tmp, base64_encode(gzcompress(self::script(), 9)));
            $hash = sha1(self::script());
            $name = basename($path);
            $php = '$d=gzuncompress(base64_decode(file_get_contents("' . $tmp . '")));@unlink("' . $tmp . '");'
                . 'if($d===false||sha1($d)!=="' . $hash . '"){echo "ACXBAD";exit(1);}'
                . '@mkdir("' . self::DIR . '",0700,true);file_put_contents("' . $path . '",$d);chmod("' . $path . '",0600);'
                . 'foreach(glob("' . self::DIR . '/guilock-*.php") as $f){if(basename($f)!=="' . $name . '"){@unlink($f);}}echo "ACXOK";';
            if (!str_contains($this->shell(self::PHP . " -r '" . $php . "'"), 'ACXOK')) {
                throw new RuntimeException('Could not copy PFCentral\'s web interface lock helper to the firewall.');
            }
        }
        Cache::put($this->cacheKey(), true, now()->addDay());
    }
}
