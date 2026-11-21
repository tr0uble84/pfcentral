<?php

use Illuminate\Broadcasting\BroadcastException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__ . '/../routes/web.php',
        api: __DIR__ . '/../routes/api.php',
        commands: __DIR__ . '/../routes/console.php',
        channels: __DIR__ . '/../routes/channels.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->prepend(\App\Http\Middleware\IntegrationApiBoundary::class);
        // Staff VPN page host (e.g. vpn.example.com): serve only /vpn there. Runs before the session starts.
        $middleware->prepend(\App\Http\Middleware\VpnPortalHost::class);
        // After an in-app update, clear PHP-FPM's opcode cache once so workers run the new release.
        $middleware->prepend(\App\Http\Middleware\ResetOpcacheAfterUpdate::class);
        $middleware->append(\App\Http\Middleware\EnsureSystemIsSetup::class);
        // Firewall web interfaces through PFCentral (System → Security), on their own host name. Runs before the session.
        $middleware->prepend(\App\Http\Middleware\FirewallGuiHost::class);
        // System → Security → "Require two-factor sign-in".
        $middleware->appendToGroup('web', \App\Http\Middleware\RequireTwoFactor::class);
        $middleware->alias([
            'deny.readonly' => \App\Http\Middleware\DenyReadOnly::class,
        ]);
        // The CFBTEL VPN Windows app redeems its one-time code here (no browser session, no CSRF token).
        $middleware->validateCsrfTokens(except: ['vpn/app/token', 'vpn/app/status', 'vpn/app/support']);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(fn (Request $request, \Throwable $e) =>
            $request->is('api/v1', 'api/v1/*') || $request->expectsJson());
        $exceptions->respond(function (\Symfony\Component\HttpFoundation\Response $response) {
            if (request()->is('api/v1', 'api/v1/*')) {
                if ($response->getStatusCode() >= 500) {
                    $response = response()->json(['message' => 'Server error.'], $response->getStatusCode());
                }
                $response->headers->set('Cache-Control', 'no-store, private');
                $response->headers->set('X-Content-Type-Options', 'nosniff');
            }
            return $response;
        });
        $exceptions->dontFlash(['api_key', 'api_secret', 'api_token', 'ssh_password', 'opn_password']);
        // Swallow Reverb/WebSocket broadcast failures so they never crash
        // a user-facing HTTP request. The operation (e.g. saving a firewall)
        // still succeeds — only the real-time push is silently skipped.
        $exceptions->report(function (BroadcastException $e): bool {
            logger()->warning('BroadcastException suppressed: ' . $e->getMessage());
            return false; // mark as handled — do not propagate
        });

        // An expired login form (left open too long, or open during an update) goes back to the
        // login page with a short note instead of the bare "419 Page Expired" screen.
        $exceptions->render(function (\Symfony\Component\HttpKernel\Exception\HttpException $e, Request $request) {
            if ($e->getStatusCode() !== 419 || !$e->getPrevious() instanceof \Illuminate\Session\TokenMismatchException) {
                return null;
            }
            if ($request->expectsJson() || !$request->is('login', 'login/magic', 'forgot-password', 'reset-password', 'two-factor-challenge', 'logout')) {
                return null;
            }
            if ($request->is('logout')) {
                return redirect()->route('login');
            }

            return redirect()->route('login')
                ->withInput($request->only('email'))
                ->with('status', 'Your session expired. Please sign in again.');
        });

        $exceptions->render(function (BroadcastException $e, Request $request) {
            // For API / AJAX requests return a soft warning, not a 500
            if ($request->expectsJson()) {
                return response()->json([
                    'warning' => 'Real-time broadcast unavailable. Changes were saved.',
                ], 200);
            }
            // For web requests, redirect back with a flash notice
            return back()->with('warning', 'Changes were saved, but real-time notifications are temporarily unavailable.');
        });
    })->create();
