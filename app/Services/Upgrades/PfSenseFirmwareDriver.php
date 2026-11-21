<?php

namespace App\Services\Upgrades;

use App\Models\Firewall;
use App\Services\FirewallHttpOptions;
use App\Services\PfSenseApiService;
use App\Services\SshHostKeyVerifier;
use Illuminate\Support\Facades\Http;
use phpseclib3\Net\SFTP;
use RuntimeException;

/**
 * pfSense through the REST API's command prompt (pfrest). Every command stays under the prompt's 1024-character
 * limit, and the upgrade itself is started with daemon(8) so the API call returns at once.
 */
class PfSenseFirmwareDriver implements FirmwareDriver
{
    public const DIR = '/root/.pfcentral';
    public const LOG = self::DIR . '/upgrade.log';

    /** Reinstalls the REST API package for the running pfSense version (its settings and keys stay in config.xml). */
    public const API_REINSTALL = 'pkg-static add https://github.com/jaredhendrickson13/pfsense-api/releases/latest/download/pfSense-$(cut -d- -f1 /etc/version)-pkg-RESTAPI.pkg';

    public function __construct(private Firewall $firewall)
    {
    }

    private function api(int $timeout = 20): PfSenseApiService
    {
        return (new PfSenseApiService($this->firewall))->setApiTimeout($timeout);
    }

    private function run(string $command, int $timeout = 20): string
    {
        if (strlen($command) > 1024) {
            throw new RuntimeException('Command too long for the firewall command prompt.');
        }
        $res = $this->api($timeout)->commandPrompt($command);
        $out = $res['data']['output'] ?? '';
        return is_array($out) ? implode("\n", $out) : (string) $out;
    }

    public function facts(): array
    {
        // [p]fSense-upgrade: the pattern doesn't match this shell's own command line.
        $out = $this->run(implode('; ', [
            'echo "version=$(cat /etc/version 2>/dev/null)"',
            'echo "patch=$(cat /etc/version.patch 2>/dev/null)"',
            'df -k / | awk \'NR==2{print "disk_free_kb="$4}\'',
            'echo "backups=$(ls /cf/conf/backup 2>/dev/null | wc -l | tr -d \' \')"',
            'echo "zfs=$(zpool list -H -o name 2>/dev/null | head -1)"',
            'echo "maker=$(kenv -q smbios.system.maker 2>/dev/null)"',
            'echo "product=$(kenv -q smbios.system.product 2>/dev/null)"',
            'echo "pf=$(pfctl -si 2>/dev/null | head -1 | awk \'{print $2}\')"',
            'pgrep -f \'[p]fSense-upgrade\' >/dev/null 2>&1 && echo running=1 || echo running=0',
            'echo "boot=$(sysctl -n kern.boottime | sed \'s/.*sec = \([0-9]*\).*/\1/\')"',
        ]));
        return self::parseFacts($out);
    }

    public static function parseFacts(string $out): array
    {
        $kv = [];
        foreach (preg_split('/\R/', $out) as $line) {
            if (preg_match('/^([a-z_]+)=(.*)$/', trim($line), $m)) {
                $kv[$m[1]] = trim($m[2]);
            }
        }
        if (($kv['version'] ?? '') === '') {
            throw new RuntimeException('The firewall didn\'t report its version.');
        }
        $int = fn ($k) => isset($kv[$k]) && ctype_digit($kv[$k]) ? (int) $kv[$k] : null;
        $patch = $kv['patch'] ?? '';
        $version = $kv['version'] . ($patch !== '' && $patch !== '0' ? " (patch {$patch})" : '');
        return [
            'version' => mb_substr($version, 0, 64),
            'disk_free_kb' => $int('disk_free_kb'),
            'backups' => $int('backups'),
            'zfs' => ($kv['zfs'] ?? '') !== '',
            'maker' => mb_substr($kv['maker'] ?? '', 0, 64),
            'product' => mb_substr($kv['product'] ?? '', 0, 64),
            'pf' => mb_substr($kv['pf'] ?? '', 0, 20),
            'running' => ($kv['running'] ?? '0') === '1',
            'boot' => $int('boot'),
        ];
    }

    public function available(): array
    {
        return self::parseAvailable($this->run('/usr/sbin/pfSense-upgrade -c 2>&1 | tail -n 6', 120));
    }

    public static function parseAvailable(string $out): array
    {
        $version = null;
        if (preg_match('/(\d[0-9A-Za-z._-]*) version of .*is available/i', $out, $m)) {
            $version = mb_substr($m[1], 0, 64);
        }
        return ['version' => $version, 'up_to_date' => $version === null && (bool) preg_match('/up to date/i', $out), 'output' => mb_substr(trim($out), -2000)];
    }

    public function backup(): string
    {
        $b64 = preg_replace('/\s+/', '', $this->run('gzip -c /cf/conf/config.xml | openssl base64 -A', 60));
        $gz = base64_decode($b64, true);
        $xml = $gz !== false ? @gzdecode($gz) : false;
        if (!is_string($xml) || !str_contains($xml, '<pfsense>')) {
            throw new RuntimeException('The configuration backup didn\'t download correctly.');
        }
        return $xml;
    }

    public function start(): void
    {
        $out = $this->run('mkdir -p ' . self::DIR . ' && rm -f ' . self::LOG . ' && /usr/sbin/daemon -f -o ' . self::LOG . ' /usr/sbin/pfSense-upgrade -y && echo started');
        if (!str_contains($out, 'started')) {
            throw new RuntimeException('The firewall didn\'t start the upgrade: ' . mb_substr(trim($out), 0, 300));
        }
    }

    public function log(): string
    {
        return mb_substr(trim($this->run('tail -n 8 ' . self::LOG . ' 2>/dev/null')), -2000);
    }

    public function reachable(): bool
    {
        try {
            Http::withOptions(FirewallHttpOptions::get($this->firewall->tls_public_key_pin, $this->firewall->url))
                ->timeout(8)->withoutRedirecting()->get(rtrim($this->firewall->url, '/') . '/');
            return true;
        } catch (\Throwable $e) {
            return false;
        }
    }

    public function canReinstallApi(): bool
    {
        return !empty($this->firewall->ssh_username) && !empty($this->firewall->ssh_password) && !empty($this->firewall->ssh_host_key_fingerprint);
    }

    public function reinstallApi(): string
    {
        if (!$this->canReinstallApi()) {
            throw new RuntimeException('SSH isn\'t set up for this firewall in PFCentral.');
        }
        $host = parse_url($this->firewall->url, PHP_URL_HOST);
        $ssh = new SFTP($host, (int) ($this->firewall->ssh_port ?? 22), 15);
        if (!SshHostKeyVerifier::verify($ssh, $this->firewall->ssh_host_key_fingerprint)) {
            throw new RuntimeException('SSH host key verification failed.');
        }
        if (!$ssh->login($this->firewall->ssh_username, $this->firewall->ssh_password)) {
            throw new RuntimeException('SSH sign-in failed.');
        }
        $ssh->setTimeout(180);
        $out = (string) $ssh->exec(self::API_REINSTALL . ' 2>&1 | tail -n 5');
        $ssh->disconnect();
        return mb_substr(trim($out), -1000);
    }
}
