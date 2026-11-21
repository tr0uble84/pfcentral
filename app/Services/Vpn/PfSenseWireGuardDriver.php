<?php

namespace App\Services\Vpn;

use App\Services\PfSenseApiService;

/**
 * WireGuard on pfSense through the pfSense REST API package (pfrest, API v2) that PFCentral already uses.
 *
 * API user privileges needed (in addition to what PFCentral already uses):
 *   api-v2-vpn-wireguard-settings-patch, -tunnels-get, -tunnel-post, -peers-get, -peer-post, -peer-delete, -apply-post,
 *   api-v2-interfaces-get, api-v2-interface-post, api-v2-interface-apply-post,
 *   api-v2-firewall-aliases-get, -alias-post, -rule-post, -apply-post,
 *   api-v2-system-packages-get, api-v2-system-package-post (to install the WireGuard package when it's missing).
 */
class PfSenseWireGuardDriver implements WireGuardDriver
{
    private const DESCR = 'CFBTEL VPN';
    private const ALIAS = 'CFBVPN_Private_Nets';
    /** Interface group the WireGuard package creates for its tunnels (Firewall → Rules → WireGuard). */
    private const IFGROUP = 'WireGuard';
    public const PACKAGE = 'pfSense-pkg-WireGuard';
    /** Installing a package downloads it on the firewall: allow a few minutes. */
    public const INSTALL_TIMEOUT = 300;
    private const READY_TRIES = 12;
    /** Seconds between "is WireGuard ready yet?" checks after installing (tests set 0). */
    public static int $readyWait = 5;

    public function __construct(private PfSenseApiService $api)
    {
    }

    private function data(array|null $res): array
    {
        return is_array($res['data'] ?? null) ? $res['data'] : [];
    }

    public function interfaces(): array
    {
        return array_map(fn ($i) => [
            'id' => (string) ($i['id'] ?? ''),
            'descr' => (string) ($i['descr'] ?? $i['id'] ?? ''),
            'ipaddr' => is_string($i['ipaddr'] ?? null) ? $i['ipaddr'] : null,
            'subnet' => is_numeric($i['subnet'] ?? null) ? (int) $i['subnet'] : null,
        ], $this->data($this->api->get('/interfaces', ['limit' => 0])));
    }

    /** pfrest answers 424 "requires the 'pfSense-pkg-WireGuard' package but it is not installed". */
    private static function isMissingPackage(\Throwable $e): bool
    {
        return str_contains($e->getMessage(), self::PACKAGE) && str_contains($e->getMessage(), 'not installed');
    }

    /**
     * Is pfSense's WireGuard package installed? Asks the firewall's installed-package list first (a WireGuard
     * endpoint can answer before the package is really there), then the WireGuard settings endpoint.
     */
    public function packageInstalled(): bool
    {
        try {
            $names = array_map(fn ($p) => (string) ($p['name'] ?? ''), $this->data($this->api->get('/system/packages', ['limit' => 0])));
            if (in_array(self::PACKAGE, $names, true)) {
                return true;
            }
            if ($names !== []) {
                return false; // the list works and WireGuard isn't on it
            }
        } catch (\Throwable) {
            // no privilege for the package list: fall back to asking WireGuard itself
        }
        try {
            $this->api->get('/vpn/wireguard/settings');
            return true;
        } catch (\Throwable $e) {
            if (self::isMissingPackage($e)) {
                return false;
            }
            throw $e;
        }
    }

    /** Install pfSense's WireGuard package when it's missing (System → Package Manager → WireGuard). @return bool installed now */
    public function ensurePackage(bool $force = false): bool
    {
        if (!$force && $this->packageInstalled()) {
            return false;
        }
        try {
            $this->api->setApiTimeout(self::INSTALL_TIMEOUT);
            $this->api->post('/system/package', ['name' => self::PACKAGE]);
        } catch (\Throwable $e) {
            $why = $e->getCode() === 403
                ? 'the PFCentral API user needs the "api-v2-system-package-post" privilege'
                : $e->getMessage();
            throw new VpnException("Couldn't install the WireGuard package on this firewall ({$why}). Install it in pfSense under System → Package Manager → Available Packages → WireGuard, then try again.");
        } finally {
            $this->api->setApiTimeout(20);
        }
        // pfSense can take a little while before the new package answers.
        for ($try = 0; $try < self::READY_TRIES; $try++) {
            if ($this->wireGuardAnswers()) {
                return true;
            }
            if (self::$readyWait > 0) {
                sleep(self::$readyWait);
            }
        }
        throw new VpnException('The WireGuard package was installed but pfSense doesn\'t answer for it yet. Wait a minute and click Create again.');
    }

    private function wireGuardAnswers(): bool
    {
        try {
            $this->api->get('/vpn/wireguard/settings');
            return true;
        } catch (\Throwable $e) {
            if (self::isMissingPackage($e)) {
                return false;
            }
            throw $e;
        }
    }

    /** Runs a WireGuard call; if pfSense says the package is missing, installs it and tries once more. */
    private function withPackage(callable $call)
    {
        try {
            return $call();
        } catch (\Throwable $e) {
            if (!self::isMissingPackage($e)) {
                throw $e;
            }
            $this->ensurePackage(true);
            return $call();
        }
    }

    public function tunnels(): array
    {
        try {
            $rows = $this->data($this->api->get('/vpn/wireguard/tunnels', ['limit' => 0]));
        } catch (\Throwable $e) {
            if (self::isMissingPackage($e)) {
                return []; // no WireGuard package yet: no tunnels (setup installs it)
            }
            throw $e;
        }
        return array_map(fn ($t) => [
            'id' => $t['id'] ?? null,
            'ref' => (string) ($t['name'] ?? ''),
            'name' => (string) ($t['descr'] ?? $t['name'] ?? ''),
            'listen_port' => (int) ($t['listenport'] ?? 0),
            'public_key' => (string) ($t['publickey'] ?? ''),
        ], $rows);
    }

    /**
     * Sets up WireGuard on the firewall. Safe to run again after a stop: a "CFBTEL VPN" tunnel on the same port
     * with no devices yet is reused, and rules, the alias, NAT and DNS entries that already exist are skipped.
     *
     * pfSense's REST API can't assign a WireGuard tunnel as an interface, so the tunnel gets its address itself and
     * the rules go on pfSense's "WireGuard" interface group (Firewall → Rules → WireGuard). A tunnel that was
     * assigned by hand keeps its interface and its rules go there.
     */
    public function setup(array $input): array
    {
        $log = [];
        $bits = (int) explode('/', $input['subnet'])[1];
        $gateway = WireGuard::firstHost($input['subnet']);
        $network = explode('/', $input['subnet'])[0];

        if ($this->ensurePackage()) {
            $log[] = 'WireGuard package installed';
        }
        $this->withPackage(fn () => $this->api->patch('/vpn/wireguard/settings', ['enable' => true, 'keep_conf' => true]));
        $log[] = 'WireGuard enabled';

        $address = [['address' => $gateway, 'mask' => $bits, 'descr' => self::DESCR]];
        $existing = collect($this->tunnels())->firstWhere('name', self::DESCR);
        if ($existing) {
            if ((int) $existing['listen_port'] !== (int) $input['listen_port'] || $this->rawPeers($existing['ref']) !== []) {
                throw new VpnException("A \"" . self::DESCR . "\" tunnel ({$existing['ref']}, UDP {$existing['listen_port']}) already exists on this firewall. Use \"Adopt an existing tunnel\" instead.");
            }
            // An earlier setup stopped part-way: finish it on the same tunnel.
            $this->api->patch('/vpn/wireguard/tunnel', ['id' => $existing['id'], 'enabled' => true, 'addresses' => $address]);
            $keys = null;
            $log[] = "Tunnel {$existing['ref']} from the earlier attempt reused";
        } else {
            $keys = WireGuard::keyPair();
            $this->api->post('/vpn/wireguard/tunnel', [
                'enabled' => true,
                'descr' => self::DESCR,
                'listenport' => (string) $input['listen_port'],
                'privatekey' => $keys['private'],
                'mtu' => 1420,
                'addresses' => $address,
            ]);
        }
        $this->api->post('/vpn/wireguard/apply', []);
        $tunnel = collect($this->tunnels())->firstWhere('name', self::DESCR);
        if (!$tunnel) {
            throw new VpnException('The tunnel was not created.');
        }
        if ($keys) {
            $log[] = "Tunnel {$tunnel['ref']} created on UDP {$input['listen_port']} ({$gateway}/{$bits})";
        }

        // Rules go on the tunnel's own interface if it was assigned by hand, otherwise on the WireGuard group.
        $assigned = collect($this->data($this->api->get('/interfaces', ['limit' => 0])))->firstWhere('if', $tunnel['ref']);
        $ruleIface = $assigned['id'] ?? self::IFGROUP;
        $log[] = $assigned ? "Rules on interface {$ruleIface}" : 'Rules on the WireGuard interface group';

        $aliases = array_column($this->data($this->api->get('/firewall/aliases', ['limit' => 0])), 'name');
        if (!in_array(self::ALIAS, $aliases, true)) {
            $this->api->post('/firewall/alias', [
                'name' => self::ALIAS,
                'type' => 'network',
                'descr' => 'RFC1918 networks (CFBTEL VPN)',
                'address' => ['10.0.0.0/8', '172.16.0.0/12', '192.168.0.0/16'],
                'detail' => ['RFC1918', 'RFC1918', 'RFC1918'],
            ]);
            $log[] = 'Alias ' . self::ALIAS . ' created';
        }

        $have = array_column($this->data($this->api->get('/firewall/rules', ['limit' => 0])), 'descr');
        $added = 0;
        $rule = function (array $r) use (&$have, &$added) {
            if (in_array($r['descr'], $have, true)) {
                return;
            }
            $this->api->post('/firewall/rule', array_merge(['ipprotocol' => 'inet', 'statetype' => 'keep state'], $r));
            $have[] = $r['descr'];
            $added++;
        };
        $vpn = fn (array $r) => $rule(array_merge(['interface' => [$ruleIface], 'source' => $input['subnet']], $r));
        $vpn(['type' => 'pass', 'protocol' => 'tcp/udp', 'destination' => '(self)', 'destination_port' => '53', 'descr' => 'CFBTEL VPN: DNS to firewall']);
        $vpn(['type' => 'block', 'destination' => '(self)', 'descr' => 'CFBTEL VPN: block firewall admin']);
        $vpn(['type' => 'pass', 'destination' => $input['lan'], 'descr' => "CFBTEL VPN: allow {$input['lan']} network"]);
        $vpn(['type' => 'block', 'destination' => self::ALIAS, 'descr' => 'CFBTEL VPN: block other private networks']);
        $vpn(['type' => 'pass', 'destination' => 'any', 'descr' => 'CFBTEL VPN: internet (full tunnel)']);
        $rule([
            'type' => 'pass', 'interface' => [$input['wan']], 'protocol' => 'udp', 'source' => 'any',
            'destination' => "{$input['wan']}:ip", 'destination_port' => (string) $input['listen_port'],
            'descr' => 'CFBTEL VPN: WireGuard',
        ]);
        $log[] = "Firewall rules: {$added} added";

        // Internet for VPN users: automatic outbound NAT doesn't cover an unassigned tunnel's network.
        $log[] = $this->ensureOutboundNat($input['wan'], $input['subnet'], (bool) $assigned);
        // DNS: let the VPN network ask the firewall's DNS Resolver.
        $log[] = $this->ensureDnsAccess($network, $bits);

        $this->api->post('/firewall/apply', []);
        $log[] = 'Firewall changes applied';

        return ['ref' => $tunnel['ref'], 'name' => self::DESCR, 'public_key' => $tunnel['public_key'] ?: ($keys['public'] ?? ''), 'gateway' => $gateway, 'log' => $log];
    }

    private function ensureOutboundNat(string $wan, string $subnet, bool $assigned): string
    {
        $mode = (string) ($this->data($this->api->get('/firewall/nat/outbound/mode'))['mode'] ?? 'automatic');
        if ($mode === 'disabled') {
            return 'Outbound NAT is disabled on this firewall: VPN users will not reach the internet until it is set to Hybrid';
        }
        if ($assigned && $mode === 'automatic') {
            return 'Outbound NAT: automatic covers the VPN network';
        }
        $descr = 'CFBTEL VPN: outbound NAT';
        $rules = array_column($this->data($this->api->get('/firewall/nat/outbound/mappings', ['limit' => 0])), 'descr');
        $note = '';
        if ($mode === 'automatic') {
            // Hybrid keeps every automatic rule and adds ours.
            $this->api->patch('/firewall/nat/outbound/mode', ['mode' => 'hybrid']);
            $note = 'switched from Automatic to Hybrid (automatic rules are kept), ';
        }
        if (!in_array($descr, $rules, true)) {
            $this->api->post('/firewall/nat/outbound/mapping', [
                'interface' => $wan, 'source' => $subnet, 'destination' => 'any',
                'target' => "{$wan}:ip", 'descr' => $descr,
            ]);
            return "Outbound NAT: {$note}rule for {$subnet} added";
        }
        return "Outbound NAT: {$note}rule for {$subnet} already there";
    }

    private function ensureDnsAccess(string $network, int $bits): string
    {
        try {
            $lists = $this->data($this->api->get('/services/dns_resolver/access_lists', ['limit' => 0]));
            foreach ($lists as $l) {
                foreach ((array) ($l['networks'] ?? []) as $n) {
                    if (($n['network'] ?? '') === $network && (int) ($n['mask'] ?? -1) === $bits) {
                        return 'DNS Resolver already allows the VPN network';
                    }
                }
            }
            $this->api->post('/services/dns_resolver/access_list', [
                'name' => 'CFBTEL VPN', 'action' => 'allow', 'description' => 'CFBTEL VPN devices',
                'networks' => [['network' => $network, 'mask' => $bits, 'description' => 'CFBTEL VPN']],
            ]);
            $this->api->post('/services/dns_resolver/apply', []);
            return 'DNS Resolver access allowed for the VPN network';
        } catch (\Throwable $e) {
            return 'DNS Resolver access not changed (' . $e->getMessage() . '): if devices can\'t look up names, allow ' . "{$network}/{$bits} under Services → DNS Resolver → Access Lists";
        }
    }

    private function rawPeers(string $tunnelRef): array
    {
        $out = [];
        foreach ($this->data($this->api->get('/vpn/wireguard/peers', ['limit' => 0])) as $p) {
            if (($p['tun'] ?? '') !== $tunnelRef) {
                continue;
            }
            $out[] = [
                'id' => (int) ($p['id'] ?? -1),
                'public_key' => (string) ($p['publickey'] ?? ''),
                'name' => (string) ($p['descr'] ?? ''),
                'addresses' => array_map(fn ($a) => (string) ($a['address'] ?? ''), is_array($p['allowedips'] ?? null) ? $p['allowedips'] : []),
            ];
        }
        return $out;
    }

    public function peers(string $tunnelRef): array
    {
        return array_map(fn ($p) => ['public_key' => $p['public_key'], 'name' => $p['name'], 'addresses' => $p['addresses']], $this->rawPeers($tunnelRef));
    }

    public function status(string $tunnelRef): array
    {
        if (!preg_match('/^[A-Za-z0-9_.-]{1,32}$/', $tunnelRef)) {
            return [];
        }
        // "wg show <if> dump" peer lines: public-key, preshared-key, endpoint, allowed-ips, latest-handshake,
        // rx, tx, keepalive. awk keeps only what's needed, so no preshared or private key leaves the firewall.
        $cmd = 'env PATH=/usr/local/bin:/usr/bin:/bin wg show ' . $tunnelRef . ' dump | awk -F"\t" \'NF==8{print $1"|"$3"|"$5"|"$6"|"$7}\'';
        $res = $this->api->commandPrompt($cmd);
        $output = $res['data']['output'] ?? '';
        $output = is_array($output) ? implode("\n", $output) : (string) $output;
        return WireGuard::parseStatusLines($output);
    }

    public function addPeer(string $tunnelRef, array $peer): void
    {
        $this->api->post('/vpn/wireguard/peer', [
            'enabled' => true,
            'tun' => $tunnelRef,
            // pfSense allows free text: keep the person and device readable on the firewall.
            'descr' => mb_substr("{$peer['name']} {$peer['label']}", 0, 200),
            'publickey' => $peer['public_key'],
            'presharedkey' => $peer['preshared_key'],
            'allowedips' => [['address' => $peer['address'], 'mask' => 32, 'descr' => $peer['name']]],
        ]);
        $this->api->post('/vpn/wireguard/apply', []);
    }

    public function removePeers(string $tunnelRef, array $publicKeys): int
    {
        $want = array_flip($publicKeys);
        // pfrest ids are list positions and shift after each delete, so delete the highest first.
        $doomed = array_filter($this->rawPeers($tunnelRef), fn ($p) => isset($want[$p['public_key']]) && str_starts_with($p['name'], WireGuard::PEER_PREFIX));
        usort($doomed, fn ($a, $b) => $b['id'] <=> $a['id']);
        foreach ($doomed as $p) {
            $this->api->delete('/vpn/wireguard/peer?id=' . $p['id']);
        }
        if ($doomed) {
            $this->api->post('/vpn/wireguard/apply', []);
        }
        return count($doomed);
    }
}
