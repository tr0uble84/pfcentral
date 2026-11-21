<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\Auth\MspSignIn;
use App\Services\Vpn\VpnException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;

/**
 * "Sign in with Microsoft 365" on the PFCentral login page, for the MSP's own staff.
 * People in one of the chosen groups of the MSP's tenant become administrators (all companies).
 */
class MicrosoftLoginController extends Controller
{
    private const PENDING = 'msp_ms_pending';

    public function redirect(Request $request): RedirectResponse
    {
        if (!MspSignIn::enabled()) {
            return $this->back('Microsoft 365 sign-in is not set up yet.');
        }
        try {
            $login = MspSignIn::microsoft()->loginUrl(MspSignIn::redirectUri());
        } catch (\Throwable $e) {
            return $this->back($e instanceof VpnException ? $e->getMessage() : 'Could not start the Microsoft 365 sign-in.');
        }
        $request->session()->put(self::PENDING, $login['pending'] + ['exp' => time() + 600]);

        return redirect()->away($login['url']);
    }

    public function callback(Request $request): RedirectResponse
    {
        $pending = $request->session()->pull(self::PENDING);
        if ($request->query('error')) {
            $text = strtok((string) $request->query('error_description', ''), "\r\n") ?: 'Microsoft 365 sign-in was cancelled.';

            return $this->back(str_contains($text, 'AADSTS50011')
                ? 'Microsoft does not know this sign-in address yet. In Sign-in settings, copy the redirect URI into the Microsoft app.'
                : $text);
        }
        if (!is_array($pending) || ($pending['exp'] ?? 0) < time() || !hash_equals((string) ($pending['state'] ?? ''), (string) $request->query('state', ''))) {
            return $this->back('That sign-in link expired. Please try again.');
        }
        if (!MspSignIn::enabled()) {
            return $this->back('Microsoft 365 sign-in is not set up yet.');
        }

        $key = 'msp-ms-login:' . $request->ip();
        if (RateLimiter::tooManyAttempts($key, 10)) {
            return $this->back('Too many sign-in attempts. Try again in a few minutes.');
        }
        RateLimiter::hit($key, 300);

        try {
            $microsoft = MspSignIn::microsoft();
            $id = $microsoft->redeem((string) $request->query('code', ''), MspSignIn::redirectUri(), $pending);
            if (strtolower($id['tid']) !== strtolower((string) MspSignIn::tenantId())) {
                return $this->back('That Microsoft account is not from your organization.');
            }
            $allowed = array_column(MspSignIn::groups(), 'id');
            if (!$microsoft->memberOf($id['tid'], $id['oid'], $allowed)) {
                Log::notice('PFCentral Microsoft sign-in refused: not in an allowed group', ['upn' => $id['upn']]);

                return $this->back('Your Microsoft 365 account is not in a group that may sign in to ' . config('brand.name') . '. Ask your administrator.');
            }
        } catch (\Throwable $e) {
            Log::warning('PFCentral Microsoft sign-in failed', ['error' => $e->getMessage()]);

            return $this->back($e instanceof VpnException ? $e->getMessage() : 'Microsoft 365 sign-in failed. Please try again.');
        }

        $user = User::where('ms_oid', $id['oid'])->first();
        if (!$user) {
            $user = User::whereRaw('LOWER(email) = ?', [strtolower($id['upn'])])->first();
            if ($user && !$user->isGlobalAdmin()) {
                // Never turn a customer-company account into an administrator just because the email matches.
                return $this->back('There is already a ' . config('brand.name') . ' account with this email in a customer company. Ask an administrator to change it.');
            }
        }

        if (!$user) {
            $user = new User();
            $user->forceFill([
                'name' => $id['name'] ?: $id['upn'],
                'email' => $id['upn'],
                'password' => Hash::make(Str::random(48)),
                'email_verified_at' => now(),
            ]);
        }
        // For now every allowed person gets one role: administrator of all companies.
        $user->forceFill([
            'role' => 'admin',
            'company_id' => null,
            'ms_oid' => $id['oid'],
            'ms_upn' => $id['upn'],
            'name' => $user->name ?: $id['name'],
            'last_login_at' => now(),
        ])->save();

        RateLimiter::clear($key);
        Auth::login($user);
        $request->session()->regenerate();
        $request->session()->put(\App\Http\Middleware\RequireTwoFactor::SESSION_VIA, 'microsoft'); // Microsoft 365 handles two-factor
        Log::info('PFCentral Microsoft sign-in', ['user' => $user->id, 'upn' => $id['upn']]);

        return redirect()->intended(route('dashboard', absolute: false));
    }

    private function back(string $message): RedirectResponse
    {
        return redirect()->route('login')->withErrors(['microsoft' => $message]);
    }
}
