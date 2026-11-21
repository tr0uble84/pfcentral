<?php

namespace App\Services\Vpn;

use App\Models\Company;
use App\Models\Vpn\VpnAuditLog;
use App\Models\Vpn\VpnUser;
use Illuminate\Support\Facades\Hash;

/** Email-and-password VPN accounts: invitations, password links, sign-in with lockout. */
class VpnAccounts
{
    public const MIN_PASSWORD = 12;
    public const MAX_FAILURES = 5;
    public const LOCK_MINUTES = 15;

    public function __construct(private VpnMailer $mailer)
    {
    }

    public static function cleanEmail(mixed $email): ?string
    {
        $email = strtolower(trim((string) $email));
        return filter_var($email, FILTER_VALIDATE_EMAIL) && strlen($email) <= 190 ? $email : null;
    }

    /** Is this email already a VPN person anywhere (local accounts are found by email at sign-in)? */
    public static function emailTaken(string $email, ?int $exceptId = null): bool
    {
        return VpnUser::where('upn', $email)->when($exceptId, fn ($q) => $q->where('id', '!=', $exceptId))->exists();
    }

    /** @param  ?string  $mfaMode  null = company setting, "optional" or "required" */
    public function invite(Company $company, string $name, string $email, string $actor, ?string $mfaMode = null): VpnUser
    {
        $user = VpnUser::create([
            'company_id' => $company->id,
            'auth_type' => 'local',
            'ms_oid' => null,
            'upn' => $email,
            'name' => $name,
            'added_by' => $actor,
            'mfa_mode' => $mfaMode,
        ]);
        VpnAuditLog::record($company->id, $actor, "vpn_invited {$email}" . ($mfaMode ? " (two-factor {$mfaMode})" : ''));
        $this->sendLink($user, false);
        return $user;
    }

    /** New single-use link (invitation: 7 days; password reset: 1 hour). Old links stop working. */
    public function sendLink(VpnUser $user, bool $reset): void
    {
        $token = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
        $expires = $reset ? now()->addHour() : now()->addDays(7);
        $user->update(['invite_token_hash' => hash('sha256', $token), 'invite_expires_at' => $expires, 'invited_at' => $reset ? $user->invited_at : now()]);
        $this->mailer->sendPasswordLink($user, VpnSettings::portalUrl('/vpn/password/' . $token), $expires, $reset);
    }

    public static function userForToken(string $token): ?VpnUser
    {
        if (!preg_match('/^[A-Za-z0-9_-]{40,60}$/', $token)) {
            return null;
        }
        $user = VpnUser::where('auth_type', 'local')->where('invite_token_hash', hash('sha256', $token))->first();
        return $user && $user->invite_expires_at && $user->invite_expires_at->isFuture() ? $user : null;
    }

    public function setPassword(VpnUser $user, string $password): void
    {
        $user->update([
            'password' => Hash::make($password),
            'invite_token_hash' => null,
            'invite_expires_at' => null,
            'password_set_at' => now(),
            'failed_logins' => 0,
            'locked_until' => null,
        ]);
        VpnAuditLog::record($user->company_id, $user->upn, 'password_set');
    }

    /** @return array{user: ?VpnUser, error: ?string} */
    public function attempt(string $email, string $password): array
    {
        $user = VpnUser::findLocalByEmail($email);
        if (!$user || !$user->password) {
            Hash::check($password, '$2y$12$' . str_repeat('a', 53)); // same timing as a real check
            return ['user' => null, 'error' => 'Wrong email or password.'];
        }
        if ($user->locked_until && $user->locked_until->isFuture()) {
            return ['user' => null, 'error' => 'Too many wrong passwords. Try again in ' . self::LOCK_MINUTES . ' minutes, or use "Forgot password?".'];
        }
        if (!Hash::check($password, $user->password)) {
            $fails = $user->failed_logins + 1;
            $lock = $fails >= self::MAX_FAILURES;
            $user->update(['failed_logins' => $lock ? 0 : $fails, 'locked_until' => $lock ? now()->addMinutes(self::LOCK_MINUTES) : null]);
            if ($lock) {
                VpnAuditLog::record($user->company_id, 'system', "account_locked {$user->upn} (too many wrong passwords)");
            }
            return ['user' => null, 'error' => $lock ? 'Too many wrong passwords. Try again in ' . self::LOCK_MINUTES . ' minutes, or use "Forgot password?".' : 'Wrong email or password.'];
        }
        $user->update(['failed_logins' => 0, 'locked_until' => null]);
        return ['user' => $user, 'error' => null];
    }
}
