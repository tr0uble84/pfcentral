<?php

namespace App\Services;

use App\Models\Firewall;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Manages the pfBlockerNG package on a pfSense firewall.
 *
 * pfBlockerNG has no REST API of its own, so PFCentral copies a small PHP script
 * (resources/firewall-scripts/pfblockerng-bridge.php) to the firewall through the
 * REST API command prompt and runs it with pfSense's own PHP. The script reads and
 * writes the same config.xml sections the pfBlockerNG pages use.
 *
 * The command prompt accepts at most 1024 characters per command, so the script and
 * any long arguments are sent in pieces and put back together on the firewall.
 */
class PfBlockerNgService
{
    public const PACKAGE = 'pfSense-pkg-pfBlockerNG-devel';

    protected const MARK = 'ACXPFB:';
    protected const DIR = '/root/.pfcentral';
    protected const CHUNK = 900;
    protected const PHP = '/usr/local/bin/php';

    protected PfSenseApiService $api;

    public function __construct(protected Firewall $firewall, ?PfSenseApiService $api = null)
    {
        $this->api = $api ?? new PfSenseApiService($firewall);
        $this->api->setApiTimeout(60);
    }

    public function supported(): bool
    {
        return !$this->firewall->isOpnSense();
    }

    public function status(): array
    {
        return $this->call('status');
    }

    public function progress(): array
    {
        return $this->call('progress');
    }

    public function call(string $action, array $args = []): array
    {
        if (!$this->supported()) {
            throw new RuntimeException('pfBlockerNG is a pfSense package. OPNsense firewalls are not supported here.');
        }

        $this->ensureBridge();
        $output = $this->run($action, $args);

        if (str_contains($output, 'Could not open input file')) {
            // The script was removed from the firewall (reset or restore). Copy it again once.
            Cache::forget($this->cacheKey());
            $this->ensureBridge();
            $output = $this->run($action, $args);
        }

        $result = $this->decode($output);
        if (($result['ok'] ?? false) !== true) {
            throw new RuntimeException($result['error'] ?? 'pfBlockerNG did not accept the request.');
        }

        return $result;
    }

    /**
     * Install the pfBlockerNG package through the pfSense package manager.
     */
    public function install(): void
    {
        $this->api->setApiTimeout(300);
        $this->api->installSystemPackage(self::PACKAGE);
        $this->api->setApiTimeout(60);
    }

    protected function run(string $action, array $args): string
    {
        $command = self::PHP . ' ' . $this->bridgePath() . ' ' . escapeshellarg($action);
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

    protected function decode(string $output): array
    {
        if (!preg_match('/' . self::MARK . '([A-Za-z0-9+\/=]+)/', $output, $m)) {
            $snippet = trim(Str::limit(preg_replace('/\s+/', ' ', $output), 300));
            throw new RuntimeException('Unexpected answer from the firewall' . ($snippet !== '' ? ": {$snippet}" : '.'));
        }
        $data = json_decode((string) base64_decode($m[1]), true);
        if (!is_array($data)) {
            throw new RuntimeException('The firewall sent an answer PFCentral could not read.');
        }

        return $data;
    }

    /* -------------------------------------------------------------- bridge */

    public static function script(): string
    {
        static $script = null;

        return $script ??= php_strip_whitespace(resource_path('firewall-scripts/pfblockerng-bridge.php'));
    }

    public static function hash(): string
    {
        return sha1(self::script());
    }

    public function bridgePath(): string
    {
        return self::DIR . '/pfbng-' . substr(self::hash(), 0, 12) . '.php';
    }

    protected function cacheKey(): string
    {
        return 'pfbng_bridge:' . $this->firewall->id . ':' . substr(self::hash(), 0, 12);
    }

    protected function ensureBridge(): void
    {
        if (Cache::get($this->cacheKey())) {
            return;
        }

        $path = $this->bridgePath();
        $check = $this->shell('test -f ' . $path . ' && echo ACXOK || echo ACXMISSING');
        if (!str_contains($check, 'ACXOK')) {
            $tmp = '/tmp/acx_up_' . Str::lower(Str::random(16));
            $this->upload($tmp, base64_encode(gzcompress(self::script(), 9)));

            $name = basename($path);
            $php = '$d=gzuncompress(base64_decode(file_get_contents("' . $tmp . '")));@unlink("' . $tmp . '");'
                . 'if($d===false||sha1($d)!=="' . self::hash() . '"){echo "ACXBAD";exit(1);}'
                . '@mkdir("' . self::DIR . '",0700,true);file_put_contents("' . $path . '",$d);chmod("' . $path . '",0600);'
                . 'foreach(glob("' . self::DIR . '/pfbng-*.php") as $f){if(basename($f)!=="' . $name . '"){@unlink($f);}}echo "ACXOK";';
            $result = $this->shell(self::PHP . " -r '" . $php . "'");
            if (!str_contains($result, 'ACXOK')) {
                throw new RuntimeException('Could not copy the PFCentral pfBlockerNG helper to the firewall.');
            }
        }

        Cache::put($this->cacheKey(), true, now()->addDay());
    }

    /**
     * Write a long base64 string to a file on the firewall, a piece at a time.
     */
    protected function upload(string $file, string $base64): void
    {
        foreach (str_split($base64, self::CHUNK) as $piece) {
            $this->shell("printf '%s' '" . $piece . "' >> " . $file);
        }
    }

    protected function shell(string $command): string
    {
        if (strlen($command) > 1024) {
            throw new RuntimeException('Command too long for the firewall command prompt.');
        }
        $response = $this->api->commandPrompt($command);
        $output = $response['data']['output'] ?? '';

        return is_array($output) ? implode("\n", $output) : (string) $output;
    }
}
