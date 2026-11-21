<?php

namespace App\Services\Deploy;

use App\Models\Firewall;
use App\Services\PfSenseApiService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Runs PFCentral's deployment helper (resources/firewall-scripts/deploy.php) on a pfSense firewall:
 * check (what each change would do), apply (all changes in one save, undone by the firewall itself if it
 * can't load the new rules) and undo.
 */
class DeployHelper
{
    private const MARK = 'ACXDP:';
    private const DIR = '/root/.pfcentral';
    private const CHUNK = 900;
    private const PHP = '/usr/local/bin/php';

    /** Tests: fn (Firewall $fw, string $action, array $args): array */
    public static ?\Closure $fake = null;

    public function __construct(private Firewall $firewall)
    {
    }

    /** @return array{version: string, pfblockerng: array, interfaces: array, plan: array} */
    public function check(array $items): array
    {
        return $this->call('check', ['items' => $items]);
    }

    /** Throws DeployFailed (with the firewall's answer) when nothing was changed or the change was undone. */
    public function apply(array $items, string $label): array
    {
        return $this->call('apply', ['items' => $items, 'label' => $label]);
    }

    /** What the firewall has, as deployment items (to copy to other firewalls). */
    public function export(): array
    {
        return $this->call('export');
    }

    public function undo(array $undo, string $label): array
    {
        return $this->call('undo', ['undo' => $undo, 'label' => $label]);
    }

    public function call(string $action, array $args = []): array
    {
        if (self::$fake) {
            $r = (self::$fake)($this->firewall, $action, $args);
        } else {
            if ($this->firewall->isOpnSense()) {
                throw new RuntimeException('Deployments are for pfSense firewalls; OPNsense isn\'t supported yet.');
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
            throw new DeployFailed($r['error'] ?? 'The firewall didn\'t accept the request.', $r);
        }
        return $r;
    }

    private function api(): PfSenseApiService
    {
        return (new PfSenseApiService($this->firewall))->setApiTimeout(150);
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
                $file = '/tmp/acx_d_' . Str::lower(Str::random(16));
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
        return $s ??= php_strip_whitespace(resource_path('firewall-scripts/deploy.php'));
    }

    private function path(): string
    {
        return self::DIR . '/deploy-' . substr(sha1(self::script()), 0, 12) . '.php';
    }

    private function cacheKey(): string
    {
        return 'deploy_script:' . $this->firewall->id . ':' . substr(sha1(self::script()), 0, 12);
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
                . 'foreach(glob("' . self::DIR . '/deploy-*.php") as $f){if(basename($f)!=="' . $name . '"){@unlink($f);}}echo "ACXOK";';
            if (!str_contains($this->shell(self::PHP . " -r '" . $php . "'"), 'ACXOK')) {
                throw new RuntimeException('Could not copy PFCentral\'s deployment helper to the firewall.');
            }
        }
        Cache::put($this->cacheKey(), true, now()->addDay());
    }
}
