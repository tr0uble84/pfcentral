<?php

namespace App\Http\Middleware;

use App\Models\Company;
use App\Models\IntegrationAccessToken;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class AuthorizeIntegrationToken
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        $token = $user?->currentAccessToken();

        // Reject session authentication, transient tokens, unscoped tokens and wildcard abilities.
        abort_unless($request->bearerToken() && $token instanceof IntegrationAccessToken, 401);
        if ($token->expires_at) {
            abort_unless($token->expires_at->isFuture(), 401);
        } else {
            abort_unless(in_array('inventory:never-expires', $token->abilities ?? [], true)
                && $user->isGlobalAdmin(), 401);
        }
        abort_unless(in_array('inventory:read', $token->abilities ?? [], true), 403);
        abort_unless(in_array($user->role, ['admin', 'user', 'readonly'], true), 403);

        $allCompanies = in_array('inventory:read:all', $token->abilities ?? [], true);
        if ($allCompanies) {
            // Broad inventory access must be explicit and still have a current global-admin owner.
            abort_unless($user->isGlobalAdmin() && $token->company_id === null, 403);
        } else {
            abort_unless($token->company_id && Company::whereKey($token->company_id)->exists(), 403);
            abort_unless($user->isGlobalAdmin() || (int) $user->company_id === $token->company_id, 403);
        }

        $request->attributes->set('integration_company_id', $token->company_id);
        $request->attributes->set('integration_all_companies', $allCompanies);

        return $next($request);
    }
}
