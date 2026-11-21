<?php

namespace App\Services\Vpn;

use App\Models\Company;
use App\Models\Vpn\VpnAuditLog;
use App\Models\Vpn\VpnCompany;
use App\Models\Vpn\VpnUser;
use BaconQrCode\Renderer\Image\SvgImageBackEnd;
use BaconQrCode\Renderer\ImageRenderer;
use BaconQrCode\Renderer\RendererStyle\RendererStyle;
use BaconQrCode\Writer;
use PragmaRX\Google2FA\Google2FA;

/**
 * Two-factor sign-in with an authenticator app (Google Authenticator, Microsoft Authenticator, …) for
 * email-and-password VPN accounts. Microsoft 365 accounts use Microsoft's own MFA.
 *
 * Each person follows the company setting (optional / required) unless set otherwise on their account.
 */
class VpnMfa
{
    public const MODES = ['optional' => 'Optional', 'required' => 'Required'];
    public const RECOVERY_CODES = 8;

    private static function engine(): Google2FA
    {
        $g = new Google2FA();
        $g->setWindow(1); // the code before and after the current one also count (clock drift)
        return $g;
    }

    public static function companyMode(int $companyId): string
    {
        $m = VpnCompany::where('company_id', $companyId)->value('mfa_mode');
        return isset(self::MODES[$m]) ? $m : 'optional';
    }

    /** "optional" or "required" for this person (their own setting, else the company's). */
    public static function mode(VpnUser $user): string
    {
        return isset(self::MODES[$user->mfa_mode]) ? $user->mfa_mode : self::companyMode($user->company_id);
    }

    public static function required(VpnUser $user): bool
    {
        return $user->isLocal() && self::mode($user) === 'required';
    }

    public static function enabled(VpnUser $user): bool
    {
        return $user->isLocal() && $user->mfa_enabled_at !== null && $user->mfa_secret;
    }

    public static function newSecret(): string
    {
        return self::engine()->generateSecretKey(32);
    }

    /** The otpauth:// link the authenticator app reads from the QR code. */
    public static function uri(VpnUser $user, string $secret): string
    {
        $company = Company::find($user->company_id)?->name;
        $issuer = 'CFBTEL VPN' . ($company ? " ({$company})" : '');
        return self::engine()->getQRCodeUrl($issuer, $user->upn, $secret);
    }

    public static function qrSvg(VpnUser $user, string $secret): string
    {
        return (new Writer(new ImageRenderer(new RendererStyle(220, 1), new SvgImageBackEnd())))->writeString(self::uri($user, $secret));
    }

    /** "123 456" → "123456"; recovery codes keep letters. */
    private static function clean(string $code): string
    {
        return strtoupper(preg_replace('/[\s-]+/', '', $code));
    }

    /** The time step a 6-digit code matches (newer than the last one used), or null. */
    public static function check(string $secret, string $code, ?int $lastStep): ?int
    {
        $code = self::clean($code);
        if (!preg_match('/^\d{6}$/', $code)) {
            return null;
        }
        $step = self::engine()->verifyKeyNewer($secret, $code, $lastStep ?? 0);
        return $step === false ? null : (is_int($step) ? $step : (int) floor(time() / 30));
    }

    /**
     * Checks a sign-in code: an authenticator code (once only) or one of the recovery codes (each once).
     *
     * @return "app"|"recovery"|null
     */
    public static function verify(VpnUser $user, string $code): ?string
    {
        if (!self::enabled($user)) {
            return null;
        }
        if ($step = self::check($user->mfa_secret, $code, $user->mfa_last_step)) {
            $user->update(['mfa_last_step' => $step]);
            return 'app';
        }
        $hash = hash('sha256', self::clean($code));
        $codes = (array) ($user->mfa_recovery ?? []);
        if (strlen(self::clean($code)) === 10 && in_array($hash, $codes, true)) {
            $user->update(['mfa_recovery' => array_values(array_diff($codes, [$hash]))]);
            VpnAuditLog::record($user->company_id, $user->upn, 'mfa_recovery_code_used (' . (count($codes) - 1) . ' left)');
            return 'recovery';
        }
        return null;
    }

    /**
     * Turns two-factor on with a secret the person proved they set up (code checked by the caller).
     *
     * @return string[] the one-time recovery codes, shown once
     */
    public static function enable(VpnUser $user, string $secret, int $step): array
    {
        $codes = [];
        $alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
        for ($i = 0; $i < self::RECOVERY_CODES; $i++) {
            $c = '';
            for ($j = 0; $j < 10; $j++) {
                $c .= $alphabet[random_int(0, strlen($alphabet) - 1)];
            }
            $codes[] = substr($c, 0, 5) . '-' . substr($c, 5);
        }
        $user->update([
            'mfa_secret' => $secret,
            'mfa_enabled_at' => now(),
            'mfa_last_step' => $step,
            'mfa_recovery' => array_map(fn ($c) => hash('sha256', self::clean($c)), $codes),
        ]);
        VpnAuditLog::record($user->company_id, $user->upn, 'mfa_enabled');
        return $codes;
    }

    public static function disable(VpnUser $user, string $actor, string $why = 'mfa_disabled'): void
    {
        $user->update(['mfa_secret' => null, 'mfa_enabled_at' => null, 'mfa_recovery' => null, 'mfa_last_step' => null]);
        VpnAuditLog::record($user->company_id, $actor, "{$why} {$user->upn}");
    }

    /** For the admin page: "on", "required, not set up yet", "off". */
    public static function label(VpnUser $user): string
    {
        return match (true) {
            self::enabled($user) => '2FA on',
            self::required($user) => '2FA required, not set up yet',
            default => '2FA off',
        };
    }
}
