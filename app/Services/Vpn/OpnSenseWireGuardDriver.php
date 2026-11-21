<?php

namespace App\Services\Vpn;

use App\Services\OpnSenseApiService;

/**
 * WireGuard on OPNsense through its native API (core WireGuard, 24.x+ with Firewall → Automation).
 *
 * The tunnel is an "instance" (server); devices are "peers" (clients) linked to it. Rules are created as
 * Automation filter rules on the WireGuard interface group (no interface assignment needed), and an
 * Automation source NAT rule sends the VPN network out of the WAN address.
 */
class OpnSenseWireGuardDriver implements WireGuardDriver
{
    private const ALIAS = 'CFBVPN_Private_Nets';

    public function __construct(private OpnSenseApiService $api)
    {
    }

    /** OPNsense answers 200 with result=failed / validations on errors. */
    private function check(mixed $res, string $what): array
    {
        $res = is_array($res) ? $res : [];
        if (($res['result'] ?? '') === 'failed' || !empty($res['validations'])) {
            $details = collect($res['validations'] ?? [])->map(fn ($v, $k) => $k . ': ' . (is_array($v) ? implode(', ', $v) : $v))->implode('; ');
            throw new VpnException("{$what} failed on OPNsense" . ($details ? ": {$details}" : '.'));
        }
        return $res;
    }

    public function interfaces(): array
    {
        $rows = $this->api->get('/api/interfaces/overview/interfacesInfo')['rows'] ?? [];
        $out = [];
        foreach ($rows as $r) {
            $id = (string) ($r['identifier'] ?? '');
            if ($id === '' || ($r['description'] ?? '') === 'Unassigned Interface') {
                continue;
            }
            $v4 = $r['ipv4'][0]['ipaddr'] ?? ($r['addr4'] ?? null);
            [$ip, $bits] = array_pad(explode('/', (string) $v4), 2, null);
            $out[] = ['id' => $id, 'descr' => (string) ($r['description'] ?? $id), 'ipaddr' => $ip ?: null, 'subnet' => is_numeric($bits) ? (int) $bits : (is_numeric($r['ipv4'][0]['subnetbits'] ?? null) ? (int) $r['ipv4'][0]['subnetbits'] : null)];
        }
        return $out;
    }

    public function tunnels(): array
    {
        return array_map(fn ($t) => [
            'ref' => (string) ($t['uuid'] ?? ''),
            'name' => (string) ($t['name'] ?? ''),
            'listen_port' => (int) ($t['port'] ?? 0),
            'public_key' => (string) ($t['pubkey'] ?? ''),
        ], $this->api->getWireGuardTunnels()['data'] ?? []);
    }

    public function setup(array $input): array
    {
        $log = [];
        $bits = (int) explode('/', $input['subnet'])[1];
        $gateway = WireGuard::firstHost($input['subnet']);

        foreach ($this->tunnels() as $t) {
            if ($t['name'] === WireGuard::TUNNEL_NAME) {
                throw new VpnException('A "' . WireGuard::TUNNEL_NAME . '" instance already exists on this firewall. Use "Adopt existing tunnel" instead.');
            }
        }

        $this->check($this->api->post('/api/wireguard/general/set', ['general' => ['enabled' => '1']]), 'Enabling WireGuard');
        $log[] = 'WireGuard enabled';

        $keys = WireGuard::keyPair();
        $res = $this->check($this->api->post('/api/wireguard/server/addServer', ['server' => [
            'enabled' => '1',
            'name' => WireGuard::TUNNEL_NAME,
            'port' => (string) $input['listen_port'],
            'tunneladdress' => "{$gateway}/{$bits}",
            'pubkey' => $keys['public'],
            'privkey' => $keys['private'],
            'mtu' => '1420',
            'dns' => '',
            'disableroutes' => '0',
            'peers' => '',
        ]]), 'Creating the WireGuard instance');
        $uuid = $res['uuid'] ?? (collect($this->tunnels())->firstWhere('name', WireGuard::TUNNEL_NAME)['ref'] ?? null);
        if (!$uuid) {
            throw new VpnException('The WireGuard instance was not created.');
        }
        $this->api->post('/api/wireguard/service/reconfigure');
        $log[] = "Instance " . WireGuard::TUNNEL_NAME . " created on UDP {$input['listen_port']} ({$gateway}/{$bits})";

        $aliases = array_column($this->api->get('/api/firewall/alias/searchItem')['rows'] ?? [], 'name');
        if (!in_array(self::ALIAS, $aliases, true)) {
            $this->check($this->api->post('/api/firewall/alias/addItem', ['alias' => [
                'enabled' => '1', 'name' => self::ALIAS, 'type' => 'network',
                'content' => "10.0.0.0/8\n172.16.0.0/12\n192.168.0.0/16", 'description' => 'RFC1918 networks (CFBTEL VPN)',
            ]]), 'Creating the private networks alias');
            $this->api->post('/api/firewall/alias/reconfigure');
            $log[] = 'Alias ' . self::ALIAS . ' created';
        }

        $seq = 50000;
        $rule = function (array $r) use (&$seq, $input) {
            $this->check($this->api->post('/api/firewall/filter/addRule', ['rule' => array_merge([
                'enabled' => '1', 'interface' => 'wireguard', 'direction' => 'in', 'ipprotocol' => 'inet',
                'protocol' => 'any', 'source_net' => $input['subnet'], 'destination_net' => 'any', 'sequence' => (string) $seq++,
            ], $r)]), 'Adding a firewall rule');
        };
        $rule(['action' => 'pass', 'protocol' => 'TCP/UDP', 'destination_net' => '(self)', 'destination_port' => '53', 'description' => 'CFBTEL VPN: DNS to firewall']);
        $rule(['action' => 'block', 'destination_net' => '(self)', 'description' => 'CFBTEL VPN: block firewall admin']);
        $rule(['action' => 'pass', 'destination_net' => $input['lan'], 'description' => "CFBTEL VPN: allow {$input['lan']} network"]);
        $rule(['action' => 'block', 'destination_net' => self::ALIAS, 'description' => 'CFBTEL VPN: block other private networks']);
        $rule(['action' => 'pass', 'description' => 'CFBTEL VPN: internet (full tunnel)']);
        $rule(['action' => 'pass', 'interface' => $input['wan'], 'protocol' => 'UDP', 'source_net' => 'any', 'destination_net' => "{$input['wan']}ip", 'destination_port' => (string) $input['listen_port'], 'description' => 'CFBTEL VPN: WireGuard']);
        $this->api->post('/api/firewall/filter/apply');
        $log[] = "Firewall rules added (VPN rules and WAN UDP {$input['listen_port']})";

        $this->check($this->api->post('/api/firewall/source_nat/addRule', ['rule' => [
            'enabled' => '1', 'interface' => $input['wan'], 'ipprotocol' => 'inet', 'source_net' => $input['subnet'],
            'destination_net' => 'any', 'target' => "{$input['wan']}ip", 'description' => 'CFBTEL VPN: outbound NAT',
        ]]), 'Adding the outbound NAT rule');
        $this->api->post('/api/firewall/source_nat/apply');
        $log[] = 'Outbound NAT for the VPN network added';

        return ['ref' => $uuid, 'name' => WireGuard::TUNNEL_NAME, 'public_key' => $keys['public'], 'gateway' => $gateway, 'log' => $log];
    }

    /** Peers of this instance, with their uuids. */
    private function rawPeers(string $tunnelRef): array
    {
        $out = [];
        foreach ($this->api->getWireGuardPeers()['data'] ?? [] as $p) {
            if (!in_array($tunnelRef, (array) ($p['servers'] ?? []), true)) {
                continue;
            }
            $out[] = [
                'uuid' => (string) ($p['uuid'] ?? ''),
                'public_key' => (string) ($p['pubkey'] ?? ''),
                'name' => (string) ($p['name'] ?? ''),
                'addresses' => array_values(array_filter(array_map(fn ($a) => explode('/', trim($a))[0], explode(',', (string) ($p['tunneladdress'] ?? ''))))),
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
        // OPNsense 24.1+: the same data as Diagnostics → WireGuard status.
        $res = $this->api->get('/api/wireguard/service/show');
        $out = [];
        foreach ((array) ($res['rows'] ?? []) as $row) {
            if (!is_array($row) || ($row['type'] ?? '') !== 'peer' || empty($row['public-key'])) {
                continue;
            }
            $ep = (string) ($row['endpoint'] ?? '');
            $out[] = [
                'public_key' => (string) $row['public-key'],
                'endpoint' => $ep !== '' && $ep !== '(none)' ? $ep : null,
                'handshake' => (int) ($row['latest-handshake'] ?? 0),
                'rx' => (int) ($row['transfer-rx'] ?? 0),
                'tx' => (int) ($row['transfer-tx'] ?? 0),
            ];
        }
        return $out;
    }

    public function addPeer(string $tunnelRef, array $peer): void
    {
        $this->check($this->api->post('/api/wireguard/client/addClient', ['client' => [
            'enabled' => '1',
            'name' => $peer['name'],
            'pubkey' => $peer['public_key'],
            'psk' => $peer['preshared_key'],
            'tunneladdress' => "{$peer['address']}/32",
            'serveraddress' => '',
            'serverport' => '',
            'keepalive' => '',
            'servers' => $tunnelRef,
        ]]), 'Adding the device');
        $this->api->post('/api/wireguard/service/reconfigure');
    }

    public function removePeers(string $tunnelRef, array $publicKeys): int
    {
        $want = array_flip($publicKeys);
        $n = 0;
        foreach ($this->rawPeers($tunnelRef) as $p) {
            if (isset($want[$p['public_key']]) && str_starts_with($p['name'], WireGuard::PEER_PREFIX) && $p['uuid'] !== '') {
                $this->check($this->api->post("/api/wireguard/client/delClient/{$p['uuid']}"), 'Removing a device');
                $n++;
            }
        }
        if ($n) {
            $this->api->post('/api/wireguard/service/reconfigure');
        }
        return $n;
    }
}
