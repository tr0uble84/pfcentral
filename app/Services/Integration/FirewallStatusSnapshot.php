<?php

namespace App\Services\Integration;

use App\Models\Firewall;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;

class FirewallStatusSnapshot
{
    public function __construct(private FirewallStatusNormalizer $normalizer) {}

    public static function key(int $id): string { return 'integration_firewall_status_v1_'.$id; }

    public function read(Firewall $firewall): array
    {
        return $this->readMany(collect([$firewall]))[0];
    }

    /** Cache reads only. Authentication and company scoping happen before this method. */
    public function readMany(Collection $firewalls): array
    {
        $keys = [];
        foreach ($firewalls as $firewall) {
            $keys[] = self::key($firewall->id);
            $keys[] = 'firewall_status_'.$firewall->id;
        }
        $cached = $keys ? Cache::many($keys) : [];
        return $firewalls->map(function ($firewall) use ($cached) {
            $snapshot = $cached[self::key($firewall->id)] ?? null;
            if (! is_array($snapshot)) {
                $legacy = $cached['firewall_status_'.$firewall->id] ?? [];
                $snapshot = $this->normalizer->normalize($firewall->id, is_array($legacy) ? $legacy : []);
                $snapshot['collection'] = ['source' => 'existing_dashboard_cache', 'unavailable_sections' => ['services', 'firewall_rules', 'vpn']];
            }
            $snapshot['firewall_id'] = (int) $firewall->id;
            $snapshot['company_id'] = (int) $firewall->company_id;
            $checked = $this->normalizer->date($snapshot['checked_at'] ?? null);
            $age = $checked ? (int) max(0, CarbonImmutable::parse($checked)->diffInSeconds(CarbonImmutable::now(), false)) : null;
            $snapshot['age_seconds'] = $age;
            $snapshot['stale'] = $age === null || $age > config('integration-api.status_stale_after_seconds', 300);
            return $snapshot;
        })->values()->all();
    }

    public function store(int $firewallId, array $snapshot): void
    {
        Cache::put(self::key($firewallId), $snapshot, config('integration-api.status_retention_seconds', 86400));
    }
}
