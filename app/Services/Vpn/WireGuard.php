<?php

namespace App\Services\Vpn;

/** WireGuard keys, address math and client configuration. */
final class WireGuard
{
    public const KEY_RE = '/^[A-Za-z0-9+\/]{42}[AEIMQUYcgkosw480]=$/';
    public const PEER_PREFIX = 'cfbvpn-';
    public const TUNNEL_NAME = 'CFBTEL-VPN';
    public const DEVICE_NAME_RE = '/^[A-Za-z0-9 ._-]{1,32}$/';

    /** @return array{private: string, public: string} base64 keys */
    public static function keyPair(): array
    {
        if (!function_exists('sodium_crypto_scalarmult_base')) {
            throw new \RuntimeException('The PHP sodium extension is required for WireGuard keys.');
        }
        $priv = random_bytes(32);
        // Curve25519 clamping, as `wg genkey` does.
        $priv[0] = chr(ord($priv[0]) & 248);
        $priv[31] = chr((ord($priv[31]) & 127) | 64);
        $pub = sodium_crypto_scalarmult_base($priv);
        return ['private' => base64_encode($priv), 'public' => base64_encode($pub)];
    }

    public static function presharedKey(): string
    {
        return base64_encode(random_bytes(32));
    }

    public static function isKey(?string $key): bool
    {
        return is_string($key) && preg_match(self::KEY_RE, $key) === 1;
    }

    public static function cleanDeviceName(mixed $name): ?string
    {
        $name = is_string($name) ? trim($name) : '';
        return preg_match(self::DEVICE_NAME_RE, $name) ? $name : null;
    }

    /** "10.0.70.0/24" → [network int, bits]; throws on anything that isn't an IPv4 /16–/29. */
    public static function parseSubnet(string $subnet): array
    {
        if (!preg_match('#^(\d{1,3}(?:\.\d{1,3}){3})/(\d{1,2})$#', trim($subnet), $m)) {
            throw new \InvalidArgumentException("Invalid VPN network {$subnet}");
        }
        $ip = ip2long($m[1]);
        $bits = (int) $m[2];
        if ($ip === false || $bits < 16 || $bits > 29) {
            throw new \InvalidArgumentException("VPN network must be an IPv4 /16 to /29 ({$subnet})");
        }
        $mask = $bits === 0 ? 0 : (~0 << (32 - $bits)) & 0xFFFFFFFF;
        return [$ip & $mask, $bits];
    }

    /** The firewall's own address on the VPN network (first host). */
    public static function firstHost(string $subnet): string
    {
        [$net] = self::parseSubnet($subnet);
        return long2ip($net + 1);
    }

    /** Next free client address (from .2), skipping every address already used. */
    public static function allocate(string $subnet, array $used): ?string
    {
        [$net, $bits] = self::parseSubnet($subnet);
        $taken = array_flip(array_map(fn ($a) => explode('/', (string) $a)[0], $used));
        $size = 2 ** (32 - $bits);
        for ($i = 2; $i < $size - 1; $i++) {
            $candidate = long2ip($net + $i);
            if (!isset($taken[$candidate])) {
                return $candidate;
            }
        }
        return null;
    }

    public static function clientConfig(array $site, array $device): string
    {
        $dns = array_values(array_filter(array_map('trim', explode(',', (string) ($site['dns'] ?? '')))));
        $lines = [
            '[Interface]',
            "# {$device['label']}",
            "PrivateKey = {$device['private_key']}",
            "Address = {$device['address']}/32",
        ];
        if ($dns) {
            $lines[] = 'DNS = ' . implode(', ', $dns);
        }
        $lines[] = 'MTU = 1420';
        $lines[] = '';
        $lines[] = '[Peer]';
        $lines[] = "PublicKey = {$site['server_public_key']}";
        $lines[] = "PresharedKey = {$device['preshared_key']}";
        $lines[] = "AllowedIPs = {$site['allowed_ips']}";
        $lines[] = "Endpoint = {$site['endpoint']}";
        $lines[] = 'PersistentKeepalive = 25';
        return implode("\n", $lines) . "\n";
    }

    /**
     * Parse "public-key|endpoint|latest-handshake|rx|tx" lines (see PfSenseWireGuardDriver::status()).
     *
     * @return array<int, array{public_key: string, endpoint: ?string, handshake: int, rx: int, tx: int}>
     */
    public static function parseStatusLines(string $output): array
    {
        $out = [];
        foreach (preg_split('/\R/', $output) as $line) {
            $f = explode('|', trim($line));
            if (count($f) !== 5 || !self::isKey($f[0]) || !ctype_digit($f[2]) || !ctype_digit($f[3]) || !ctype_digit($f[4])) {
                continue;
            }
            $out[] = [
                'public_key' => $f[0],
                'endpoint' => $f[1] !== '' && $f[1] !== '(none)' ? mb_substr($f[1], 0, 64) : null,
                'handshake' => (int) $f[2],
                'rx' => (int) $f[3],
                'tx' => (int) $f[4],
            ];
        }
        return $out;
    }
}
