<?php

namespace App\Services\Integration;

use App\Models\Firewall;
use App\Services\FirewallApiFactory;
use Illuminate\Support\Facades\Cache;

/** Background-only telemetry reader; never called from an HTTP controller. */
class FirewallStatusCollector
{
    public function __construct(private FirewallStatusNormalizer $normalizer, private FirewallStatusSnapshot $snapshots) {}

    public function collect(Firewall $firewall): array
    {
        $unavailable = [];
        try {
            $api = FirewallApiFactory::make($firewall)->setApiTimeout(3);
            $raw = $firewall->isOpnSense() ? $this->opnsense($api, $unavailable) : $this->pfsense($api, $unavailable);
            $snapshot = $this->normalizer->normalize($firewall->id, ['online' => true, 'data' => $raw, 'checked_at' => now()->toIso8601String()]);
            if ($snapshot['system']['hostname'] === null) {
                try {
                    $hostname = $api->getSystemHostname();
                    $snapshot['system']['hostname'] = $this->normalizer->text($hostname['data']['hostname'] ?? null, 120);
                } catch (\Throwable) { /* Keep unknown hostname null. */ }
            }
            $this->traffic($firewall->id, $raw, $snapshot);

            try {
                $rules = $firewall->isOpnSense() ? $api->getFirewallRules() : $api->get('/firewall/rules', ['limit' => 0]);
                $snapshot['firewall_rules'] = $this->normalizer->integer($rules['total'] ?? $rules['total_count'] ?? null)
                    ?? count($this->normalizer->rows($rules));
            } catch (\Throwable) { $unavailable[] = 'firewall_rules'; }

            try {
                $response = $firewall->isOpnSense() ? $api->get('/api/core/service/search') : $api->getServicesStatus();
                $rows = $this->normalizer->rows($response);
                $running = 0; $known = 0; $stopped = [];
                foreach ($rows as $service) {
                    $state = $service['running'] ?? $service['status'] ?? null;
                    $isRunning = is_bool($state) ? $state : match (strtolower((string) $state)) {
                        '1', 'running', 'up', 'started', 'true' => true,
                        '0', 'stopped', 'down', 'false' => false,
                        default => null,
                    };
                    if ($isRunning !== null) $known++;
                    if ($isRunning === true) $running++;
                    if ($isRunning === false && ($name = $this->normalizer->text($service['name'] ?? $service['id'] ?? null, 80))) $stopped[] = $name;
                }
                $snapshot['services'] = ['running' => $known === count($rows) ? $running : null,
                    'total' => count($rows), 'stopped' => $stopped];
            } catch (\Throwable) { $unavailable[] = 'services'; }

            try {
                foreach ($this->normalizer->rows($api->getIpsecStatus()) as $tunnel) {
                    $snapshot['vpn'][] = $this->vpn('ipsec', $tunnel);
                }
            } catch (\Throwable) { $unavailable[] = 'vpn.ipsec'; }

            if (! $firewall->isOpnSense()) {
                foreach (['servers', 'clients'] as $kind) {
                    try {
                        foreach ($this->normalizer->rows($api->get('/status/openvpn/'.$kind)) as $tunnel) {
                            $snapshot['vpn'][] = $this->vpn('openvpn', $tunnel);
                        }
                    } catch (\Throwable) { $unavailable[] = 'vpn.openvpn.'.$kind; }
                }
                try {
                    foreach ($this->normalizer->rows($api->get('/status/wireguard/peers')) as $peer) {
                        $snapshot['vpn'][] = $this->wireguard($peer);
                    }
                } catch (\Throwable) { $unavailable[] = 'vpn.wireguard'; }
            } else {
                // Existing OPNsense getOpenVpnServerStatus reports configuration enablement,
                // not runtime state. Do not label that value as a live tunnel status.
                $unavailable[] = 'vpn.openvpn';
                try {
                    foreach ($this->normalizer->rows($api->getWireGuardServiceShow()) as $peer) {
                        $snapshot['vpn'][] = $this->wireguard($peer);
                    }
                } catch (\Throwable) { $unavailable[] = 'vpn.wireguard'; }
            }
            $snapshot['checked_at'] = now()->toIso8601String();
            $snapshot['collection'] = ['source' => 'background_collector', 'unavailable_sections' => array_values(array_unique($unavailable))];
        } catch (\Throwable) {
            // No upstream exception message, response body, URL or credential is persisted.
            $snapshot = $this->normalizer->normalize($firewall->id, ['online' => false, 'checked_at' => now()->toIso8601String()]);
            $snapshot['collection'] = ['source' => 'background_collector', 'unavailable_sections' => ['system', 'usage', 'interfaces', 'gateways', 'services', 'firewall_rules', 'vpn']];
        }
        $this->snapshots->store($firewall->id, $snapshot);
        return $snapshot;
    }

    private function vpn(string $type, array $row): array
    {
        $state = $this->normalizer->state($row['state'] ?? $row['status'] ?? null);
        if (in_array($state, ['established', 'connected', 'running', 'online'], true)) $state = 'up';
        if (in_array($state, ['disconnected', 'stopped', 'offline'], true)) $state = 'down';
        return ['type' => $type, 'name' => $this->normalizer->text($row['descr'] ?? $row['description'] ?? $row['name'] ?? $type.' tunnel', 120),
            'status' => $state,
            'remote' => $this->normalizer->address($row['remote_host'] ?? $row['remote-host'] ?? $row['remoteid'] ?? $row['remote'] ?? null)];
    }

    private function wireguard(array $peer): array
    {
        $last = $peer['latest-handshake'] ?? $peer['latest_handshake'] ?? null;
        $date = $this->normalizer->date($last);
        $epoch = $date ? strtotime($date) : $this->normalizer->number($last, 0);
        return ['type' => 'wireguard',
            'name' => $this->normalizer->text($peer['descr'] ?? $peer['name'] ?? $peer['if'] ?? $peer['interface'] ?? $peer['tunnel_device'] ?? 'WireGuard peer'),
            'status' => $epoch === null ? null : ($epoch > 0 && time() - $epoch >= 0 && time() - $epoch <= 180 ? 'up' : 'down'),
            'remote' => $this->normalizer->address($peer['endpoint'] ?? null)];
    }

    private function pfsense($api, array &$unavailable): array
    {
        $reachable = false; $result = [];
        try {
            $response = $api->getSystemStatus();
            $result = is_array($response['data'] ?? null) ? $response['data'] : [];
            $reachable = true;
        } catch (\Throwable) { $unavailable[] = 'usage'; }
        try {
            $version = $api->getSystemVersion();
            $result = array_merge($result, $version['data'] ?? []);
            $reachable = true;
        } catch (\Throwable) { $unavailable[] = 'system.version'; }
        if (! $reachable) throw new \RuntimeException('No successful live system probe.');
        try {
            // Native read-only endpoint; do not call the legacy pkg-query diagnostics helper.
            $version = $api->get('/system/restapi/version');
            $result['api_version'] = $version['data']['current_version'] ?? $version['data']['version'] ?? null;
        } catch (\Throwable) { $unavailable[] = 'system.rest_api_version'; }
        try { $result['interfaces'] = $api->getInterfacesStatus()['data'] ?? []; }
        catch (\Throwable) { $unavailable[] = 'interfaces'; }
        try {
            $gateways = $api->getGateways()['data'] ?? [];
            $result['gateways'] = $gateways['gateway'] ?? $gateways;
            try {
                $configured = $this->normalizer->rows($api->getRoutingGateways());
                foreach ($result['gateways'] as &$gateway) {
                    if (! is_array($gateway)) continue;
                    foreach ($configured as $config) {
                        if (($config['name'] ?? null) === ($gateway['name'] ?? null)) {
                            $gateway['gateway'] = $config['gateway'] ?? $config['address'] ?? null;
                            break;
                        }
                    }
                }
                unset($gateway);
            } catch (\Throwable) { /* Monitor/source IP is not a substitute for an unknown gateway address. */ }
        } catch (\Throwable) { $unavailable[] = 'gateways'; }
        try {
            $dns = $api->getSystemDns()['data'] ?? [];
            $result['dns_servers'] = $dns['dns'] ?? $dns['dns_server'] ?? $dns['dnsserver'] ?? [];
        } catch (\Throwable) { $unavailable[] = 'system.dns_servers'; }
        return $result;
    }

    private function traffic(int $id, array $raw, array &$snapshot): void
    {
        if (is_array($raw['data'] ?? null)) $raw = array_merge($raw, $raw['data']);
        $key = 'integration_firewall_counters_'.$id;
        $previous = Cache::get($key);
        $current = ['time' => microtime(true), 'interfaces' => []];
        foreach ($this->normalizer->rows($raw['interfaces'] ?? []) as $index => $interface) {
            $identifier = (string) ($interface['hwif'] ?? $interface['device'] ?? $interface['id'] ?? $interface['name'] ?? $index);
            $counters = ['in' => $this->normalizer->number($interface['inbytes'] ?? null, 0),
                'out' => $this->normalizer->number($interface['outbytes'] ?? null, 0)];
            $current['interfaces'][$identifier] = $counters;
            $elapsed = isset($previous['time']) ? $current['time'] - $previous['time'] : 0;
            foreach (['in', 'out'] as $direction) {
                $old = $previous['interfaces'][$identifier][$direction] ?? null;
                $new = $counters[$direction];
                if ($elapsed >= 1 && $elapsed <= 1800 && $old !== null && $new !== null && $new >= $old) {
                    $snapshot['interfaces'][$index][$direction.'_bps'] = round(($new - $old) * 8 / $elapsed, 2);
                } elseif (! isset($interface[$direction.'_rate_bps'])) {
                    $snapshot['interfaces'][$index][$direction.'_bps'] = null;
                }
            }
        }
        Cache::put($key, $current, 1800);
    }

    private function opnsense($api, array &$unavailable): array
    {
        // A successful live probe is required; cached version information is not proof of reachability.
        $time = $api->get('/api/diagnostics/system/systemTime');
        $result = ['uptime' => $time['uptime'] ?? null, 'cpu_load_avg' => $time['loadavg'] ?? null,
            'cpu_count' => $time['cpus'] ?? $time['ncpus'] ?? null, 'cpu_model' => $time['cpu_model'] ?? null];
        try {
            $info = $api->get('/api/diagnostics/system/systemInformation');
            $version = $info['versions'][0] ?? null;
            $result += ['hostname' => $info['name'] ?? null,
                'version' => is_string($version) ? preg_replace('/^OPNsense\s+/', '', $version) : null,
                'platform' => $info['platform'] ?? null, 'api_version' => 'OPNsense Core'];
        } catch (\Throwable) { $unavailable[] = 'system.information'; }
        try {
            $resources = $api->get('/api/diagnostics/system/systemResources');
            $memory = $resources['memory'] ?? [];
            $result['cpu_usage'] = $resources['cpu']['usage'] ?? null;
            $result['mem_usage'] = $this->ratio($memory['used'] ?? null, $memory['total'] ?? null);
            $result['swap_usage'] = $this->ratio($memory['swap_used'] ?? $memory['swap-used'] ?? null, $memory['swap_total'] ?? $memory['swap-total'] ?? null);
        } catch (\Throwable) { $unavailable[] = 'usage.resources'; }
        try {
            $disk = $api->get('/api/diagnostics/system/systemDisk');
            foreach ($this->normalizer->rows($disk) as $row) {
                if (($row['mountpoint'] ?? $row['mounted'] ?? $row['mount'] ?? '') === '/') {
                    $result['disk_usage'] = $row['used_pct'] ?? $row['capacity'] ?? $row['use%'] ?? null;
                    break;
                }
            }
        } catch (\Throwable) { $unavailable[] = 'usage.disk'; }
        try { $result['gateways'] = $api->getGateways()['data'] ?? []; }
        catch (\Throwable) { $unavailable[] = 'gateways'; }
        try {
            $overview = $api->get('/api/interfaces/overview/interfacesInfo');
            $statistics = $api->get('/api/diagnostics/interface/getInterfaceStatistics');
            $stats = [];
            foreach ($statistics['statistics'] ?? [] as $row) if (isset($row['name'])) $stats[$row['name']] = $row;
            $result['interfaces'] = [];
            foreach ($overview['rows'] ?? [] as $row) {
                if (($row['description'] ?? '') === 'Unassigned Interface') continue;
                $device = $row['device'] ?? '';
                $result['interfaces'][] = ['id' => $row['identifier'] ?? $device, 'device' => $device,
                    'name' => $row['description'] ?? $device, 'status' => $row['status'] ?? null,
                    'ipaddr' => $row['ipv4'][0]['ipaddr'] ?? null, 'subnet' => $row['ipv4'][0]['subnetbits'] ?? $row['ipv4'][0]['subnet'] ?? null,
                    'ipaddrv6' => $row['ipv6'][0]['ipaddr'] ?? null, 'media' => $row['media'] ?? null,
                    'inbytes' => $stats[$device]['received-bytes'] ?? null, 'outbytes' => $stats[$device]['sent-bytes'] ?? null];
            }
        } catch (\Throwable) { $unavailable[] = 'interfaces'; }
        return $result;
    }

    private function ratio(mixed $used, mixed $total): ?float
    {
        $used = $this->normalizer->number($used, 0); $total = $this->normalizer->number($total, 0);
        return $used !== null && $total > 0 ? $this->normalizer->number($used * 100 / $total, 0, 100) : null;
    }
}
