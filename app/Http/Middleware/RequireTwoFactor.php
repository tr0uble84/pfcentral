<?php

namespace App\Http\Middleware;

use App\Services\Security\SecuritySettings;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * System → Security → "Require two-factor sign-in": someone who signed in with a password (or an emailed link) and
 * hasn't set up two-factor yet can only reach their profile, to set it up. Microsoft 365 sign-ins count as two-factor.
 */
class RequireTwoFactor
{
    public const SESSION_VIA = 'pfc_auth_via';

    /** What stays reachable while two-factor isn't set up. */
    private const ALLOWED = ['profile.*', 'two-factor.*', 'password.confirm*', 'password.confirmation', 'logout', 'whats-new*', 'verification.*', 'system.status'];

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        if (!$user || !SecuritySettings::requireTwoFactor()) {
            return $next($request);
        }
        if (!empty($user->two_factor_secret) && $user->two_factor_confirmed_at !== null) {
            return $next($request);
        }
        if ($request->session()->get(self::SESSION_VIA) === 'microsoft') {
            return $next($request);
        }
        $route = $request->route();
        if (!$route || $request->routeIs(...self::ALLOWED) || str_starts_with($request->path(), 'user/')) {
            return $next($request); // user/... is Fortify's two-factor setup
        }
        $message = 'Your organization requires two-factor sign-in. Set it up below to continue.';
        if ($request->expectsJson()) {
            return response()->json(['message' => $message], 403);
        }
        return redirect()->route('profile.edit')->with('warning', $message);
    }
}
