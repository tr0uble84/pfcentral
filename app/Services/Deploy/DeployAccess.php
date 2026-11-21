<?php

namespace App\Services\Deploy;

use App\Models\SystemSetting;
use App\Models\User;
use App\Services\Auth\MspSignIn;
use App\Services\Vpn\Microsoft;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Who may create, start, schedule or undo deployments: Microsoft 365 people and groups of the MSP's own tenant,
 * picked by a global admin. Nobody else, whatever their PFCentral role. Until someone is picked, nobody can deploy.
 *
 * A person is matched by their Microsoft 365 account (they sign in to PFCentral with Microsoft 365). Group
 * membership is checked with Microsoft, including nested groups, and remembered for a few minutes.
 */
final class DeployAccess
{
    private const KEY = 'deploy_access';
    public const MAX = 50;
    private const CACHE_MINUTES = 5;

    /** @return array{users: array<int, array{oid: string, name: string, upn: string}>, groups: array<int, array{id: string, name: string}>} */
    public static function get(): array
    {
        $v = json_decode((string) SystemSetting::where('key', self::KEY)->value('value'), true);
        $users = array_values(array_filter((array) ($v['users'] ?? []), fn ($u) => is_array($u) && Microsoft::isGuid($u['oid'] ?? null)));
        $groups = array_values(array_filter((array) ($v['groups'] ?? []), fn ($g) => is_array($g) && Microsoft::isGuid($g['id'] ?? null)));
        return ['users' => $users, 'groups' => $groups];
    }

    public static function set(array $users, array $groups, string $by): void
    {
        $clean = [
            'users' => array_values(array_map(fn ($u) => ['oid' => strtolower($u['oid']), 'name' => mb_substr((string) ($u['name'] ?? ''), 0, 120), 'upn' => mb_substr(strtolower((string) ($u['upn'] ?? '')), 0, 200)],
                array_filter($users, fn ($u) => Microsoft::isGuid($u['oid'] ?? null)))),
            'groups' => array_values(array_map(fn ($g) => ['id' => strtolower($g['id']), 'name' => mb_substr((string) ($g['name'] ?? ''), 0, 120)],
                array_filter($groups, fn ($g) => Microsoft::isGuid($g['id'] ?? null)))),
        ];
        $clean['users'] = array_values(array_slice(array_column($clean['users'], null, 'oid'), 0, self::MAX));
        $clean['groups'] = array_values(array_slice(array_column($clean['groups'], null, 'id'), 0, self::MAX));
        SystemSetting::updateOrCreate(['key' => self::KEY], ['value' => json_encode($clean)]);
        Cache::put(self::KEY . ':v', (int) Cache::get(self::KEY . ':v', 0) + 1, now()->addDays(30));
        Log::info('Deployments access changed', ['by' => $by, 'users' => array_column($clean['users'], 'upn'), 'groups' => array_column($clean['groups'], 'name')]);
    }

    public static function configured(): bool
    {
        $a = self::get();
        return $a['users'] !== [] || $a['groups'] !== [];
    }

    /** Microsoft 365 sign-in for the MSP's staff is set up, so people and groups can be picked. */
    public static function canPick(): bool
    {
        return Microsoft::isGuid(MspSignIn::tenantId()) && MspSignIn::appReady();
    }

    public static function allows(?User $user): bool
    {
        return self::why($user) === null;
    }

    /** Null when the person may deploy; otherwise the reason they can't. */
    public static function why(?User $user): ?string
    {
        if (!$user || $user->isReadOnly()) {
            return 'Read-only accounts can\'t deploy.';
        }
        if (!self::configured()) {
            return 'Nobody can deploy yet: a global administrator picks who may, under Deployments → Who can deploy.';
        }
        $oid = strtolower((string) ($user->ms_oid ?? ''));
        if (!Microsoft::isGuid($oid) || !$user->isGlobalAdmin()) {
            return 'Only the Microsoft 365 people and groups picked under "Who can deploy" can deploy. Sign in with Microsoft 365.';
        }
        $a = self::get();
        if (in_array($oid, array_column($a['users'], 'oid'), true)) {
            return null;
        }
        if ($a['groups'] === []) {
            return 'You are not one of the people who can deploy.';
        }
        $key = self::KEY . ':' . Cache::get(self::KEY . ':v', 0) . ':' . $oid;
        $in = Cache::get($key);
        if ($in === null) {
            try {
                $in = (bool) MspSignIn::microsoft()->memberOf((string) MspSignIn::tenantId(), $oid, array_column($a['groups'], 'id'));
                Cache::put($key, $in, now()->addMinutes(self::CACHE_MINUTES));
            } catch (\Throwable $e) {
                Log::warning('Deployments access: group check failed', ['error' => $e->getMessage()]);
                return 'Couldn\'t check your Microsoft 365 groups right now. Try again in a minute.';
            }
        }
        return $in ? null : 'You are not in a group that can deploy.';
    }
}
