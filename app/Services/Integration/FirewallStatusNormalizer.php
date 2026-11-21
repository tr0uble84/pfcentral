<?php

namespace App\Services\Integration;

use Carbon\CarbonImmutable;

/** Whitelist-only serializer: raw API responses and exceptions never leave this boundary. */
class FirewallStatusNormalizer
{
    public function number(mixed $value, ?float $minimum = null, ?float $maximum = null): ?float
    {
        if (is_string($value)) $value = trim(str_replace(['%', 'ms', '°C'], '', $value));
        if (! is_numeric($value)) return null;
        $number = (float) $value;
        if (! is_finite($number) || ($minimum !== null && $number < $minimum) || ($maximum !== null && $number > $maximum)) return null;
        return round($number, 2);
    }

    public function text(mixed $value, int $limit = 200): ?string
    {
        if (! is_string($value) && ! is_numeric($value)) return null;
        $value = trim(preg_replace('/[\x00-\x1F\x7F]/u', '', (string) $value) ?? '');
        if ($value === '' || in_array(strtolower($value), ['n/a', 'unknown', 'none', 'null', '-'], true)) return null;
        return mb_substr($value, 0, $limit);
    }

    public function date(mixed $value): ?string
    {
        try {
            if (is_numeric($value) && (float) $value > 0) return CarbonImmutable::createFromTimestampUTC((float) $value)->toIso8601String();
            if (! is_string($value) || ! preg_match('/^\d{4}-\d{2}-\d{2}[ T]/', $value)) return null;
            return CarbonImmutable::parse($value)->utc()->toIso8601String();
        } catch (\Throwable) { return null; }
    }

    public function uptime(mixed $value): ?float
    {
        if (is_numeric($value)) return $this->number($value, 0);
        if (! is_string($value)) return null;
        $days = preg_match('/(\d+)\s*days?/i', $value, $day) ? (int) $day[1] : 0;
        if (preg_match('/(\d{1,4}):(\d{2}):(\d{2})/', $value, $clock)) {
            return (float) ($days * 86400 + (int) $clock[1] * 3600 + (int) $clock[2] * 60 + (int) $clock[3]);
        }
        $total = 0; $matched = false;
        foreach (['days?' => 86400, 'hours?' => 3600, 'minutes?|mins?' => 60, 'seconds?|secs?' => 1] as $unit => $multiplier) {
            if (preg_match('/(\d+)\s*(?:'.$unit.')\b/i', $value, $match)) { $total += (int) $match[1] * $multiplier; $matched = true; }
        }
        return $matched ? (float) $total : null;
    }

    public function address(mixed $value): ?string
    {
        $value = $this->text($value, 200);
        if ($value === null) return null;
        $host = explode('/', $value, 2)[0];
        if (filter_var($host, FILTER_VALIDATE_IP)) return $host;
        if (preg_match('/^\[([^\]]+)\](?::\d+)?$/', $value, $m) && filter_var($m[1], FILTER_VALIDATE_IP)) return $m[1];
        if (preg_match('/^([^:]+):\d+$/', $value, $m)) $host = $m[1];
        return filter_var($host, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME) && str_contains($host, '.') ? $host : null;
    }

    public function rows(mixed $value): array
    {
        if (! is_array($value)) return [];
        if (isset($value['data']) && is_array($value['data'])) $value = $value['data'];
        elseif (isset($value['rows']) && is_array($value['rows'])) $value = $value['rows'];
        if (isset($value['name']) || isset($value['id']) || isset($value['descr'])) $value = [$value];
        return array_values(array_filter($value, 'is_array'));
    }

    public function state(mixed $value): ?string
    {
        $value = strtolower(trim(is_scalar($value) ? (string) $value : ''));
        return in_array($value, ['up', 'down', 'online', 'offline', 'unknown', 'no carrier', 'running', 'stopped',
            'connected', 'disconnected', 'established', 'connecting', 'disabled', 'pending', 'none', 'loss', 'delay', 'force_down'], true) ? $value : null;
    }

    public function normalize(int $id, array $status): array
    {
        $raw = is_array($status['data'] ?? null) ? $status['data'] : [];
        if (is_array($raw['data'] ?? null)) $raw = array_merge($raw, $raw['data']);
        $system = is_array($raw['system'] ?? null) ? $raw['system'] : [];
        $raw = array_merge($system, $raw);
        $online = is_bool($status['online'] ?? null) ? $status['online'] : null;
        $checked = $this->date($status['checked_at'] ?? $status['updated_at'] ?? null);
        $load = $raw['cpu_load_avg'] ?? $raw['load_average'] ?? $raw['cpu']['load_avg'] ?? [];
        if (is_string($load)) $load = preg_split('/[,\s]+/', trim($load));
        $load = is_array($load) ? array_map(fn ($v) => $this->number($v, 0), array_slice(array_values($load), 0, 3)) : [];
        $count = $this->number($raw['cpu_count'] ?? $raw['cpu']['count'] ?? null, 1, 4096);
        $result = [
            'firewall_id' => $id, 'online' => $online, 'checked_at' => $checked,
            'error' => $online === false ? 'Firewall could not be reached or telemetry access was denied.' : null,
            'system' => [
                'version' => $this->text($raw['product_version'] ?? $raw['version'] ?? null, 80),
                'rest_api_version' => $this->text($raw['api_version'] ?? $status['api_version'] ?? null, 60),
                'platform' => $this->text($raw['platform'] ?? null, 120),
                'hostname' => $this->text($raw['hostname'] ?? null, 120),
                'serial' => $this->text($raw['serial'] ?? $raw['serial_number'] ?? null, 120),
                'bios' => ['vendor' => $this->text($raw['bios_vendor'] ?? $raw['bios']['vendor'] ?? null),
                    'version' => $this->text($raw['bios_version'] ?? $raw['bios']['version'] ?? null),
                    'date' => $this->text($raw['bios_date'] ?? $raw['bios']['date'] ?? null)],
                'cpu' => ['model' => $this->text($raw['cpu_model'] ?? $raw['cpu']['model'] ?? null),
                    'count' => $count === null ? null : (int) $count, 'load_avg' => $load],
                'uptime_seconds' => $this->uptime($raw['uptime_seconds'] ?? $raw['uptime'] ?? null),
                'dns_servers' => array_values(array_filter(array_map(fn ($v) => $this->address($v),
                    is_array($raw['dns_servers'] ?? null) ? array_slice($raw['dns_servers'], 0, 16) : []))),
                'last_modified' => $this->date($raw['last_config_change_ts'] ?? $raw['last_modified'] ?? $raw['last_config_change'] ?? null),
                'packages_count' => $this->integer($raw['installed_packages_count'] ?? null),
            ],
            'usage' => ['cpu_pct' => $this->number($raw['cpu_usage'] ?? null, 0, 100),
                'memory_pct' => $this->number($raw['mem_usage'] ?? null, 0, 100),
                'swap_pct' => $this->number($raw['swap_usage'] ?? null, 0, 100),
                'disk_pct' => $this->number($raw['disk_usage'] ?? null, 0, 100),
                'temperature_c' => $this->number($raw['temp_c'] ?? $raw['temperature_c'] ?? null, -50, 150)],
            'gateways' => [], 'interfaces' => [], 'firewall_rules' => $this->integer($raw['firewall_rules'] ?? null),
            'services' => ['running' => null, 'total' => null, 'stopped' => []], 'vpn' => [],
        ];
        foreach (array_slice($this->rows($raw['gateways'] ?? []), 0, 256) as $gateway) {
            $result['gateways'][] = ['name' => $this->text($gateway['name'] ?? null, 80),
                'address' => $this->address($gateway['address'] ?? $gateway['gateway'] ?? null),
                'monitor' => $this->address($gateway['monitor'] ?? $gateway['monitorip'] ?? null),
                'status' => $this->state($gateway['status'] ?? null),
                'loss_pct' => $this->number($gateway['loss_pct'] ?? $gateway['loss'] ?? null, 0, 100),
                'delay_ms' => $this->number($gateway['delay_ms'] ?? $gateway['delay'] ?? null, 0)];
        }
        foreach (array_slice($this->rows($raw['interfaces'] ?? []), 0, 1024) as $interface) {
            $v4 = $interface['ipv4'] ?? $interface['ipaddr'] ?? null;
            $prefix = $interface['subnet'] ?? (is_string($v4) && str_contains($v4, '/') ? explode('/', $v4)[1] : null);
            $result['interfaces'][] = ['id' => $this->text($interface['id'] ?? $interface['if'] ?? null, 60),
                'name' => $this->text($interface['descr'] ?? $interface['name'] ?? null, 120),
                'device' => $this->text($interface['device'] ?? $interface['hwif'] ?? $interface['if'] ?? null, 80),
                'status' => $this->state($interface['status'] ?? null),
                'ipv4' => $this->ip($v4, FILTER_FLAG_IPV4), 'subnet' => $this->integer($prefix, 32),
                'ipv6' => $this->ip($interface['ipv6'] ?? $interface['ipaddrv6'] ?? null, FILTER_FLAG_IPV6),
                'media' => $this->text($interface['media'] ?? null, 120),
                'in_bps' => $this->number($interface['in_rate_bps'] ?? null, 0),
                'out_bps' => $this->number($interface['out_rate_bps'] ?? null, 0)];
        }
        return $result;
    }

    public function integer(mixed $value, ?int $maximum = null): ?int
    {
        $number = $this->number($value, 0, $maximum);
        return $number !== null && floor($number) === $number ? (int) $number : null;
    }

    private function ip(mixed $value, int $flag): ?string
    {
        $address = $this->address($value);
        return $address !== null && filter_var($address, FILTER_VALIDATE_IP, $flag) ? $address : null;
    }
}
