<?php

namespace App\Services\Security;

use App\Models\Firewall;
use App\Models\FirewallGuiSession;
use App\Models\User;
use App\Services\FirewallHttpOptions;
use App\Services\PfSenseApiService;
use GuzzleHttp\Cookie\CookieJar;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use RuntimeException;
use Symfony\Component\HttpFoundation\Response;

/**
 * Opens a pfSense web interface through PFCentral, on PFCentral's own host name for it (System → Security).
 *
 * For each session PFCentral makes a temporary account on the firewall in the technician's name, signs in with it
 * server-side and keeps the firewall's cookie itself: the browser only ever holds PFCentral's own session cookie.
 * Every change (each non-GET request) is logged. The account is removed when the session ends, after a while
 * without clicks, or after 8 hours at most.
 */
class FirewallGui
{
    public const COOKIE = 'pfc_gui';
    public const PREFIX = '/__pfcentral';
    private const MAX_ACTIONS = 300;

    /** Start a session; returns the one-time address that opens it in the browser. */
    public static function open(User $user, Firewall $firewall, string $ip): string
    {
        $host = SecuritySettings::guiHost();
        if (!SecuritySettings::guiProxyEnabled() || !$host) {
            throw new RuntimeException('Opening firewalls through PFCentral is turned off (System → Security).');
        }
        if ($firewall->isOpnSense()) {
            throw new RuntimeException('Opening the web interface through PFCentral works for pfSense for now.');
        }
        $local = preg_replace('/[^a-z0-9]/', '', strtolower(Str::before($user->email, '@'))) ?: 'tech';
        $temp = 'pfc-' . substr($local, 0, 12) . '-' . strtolower(Str::random(4));
        $password = Str::password(32, symbols: false);

        $api = new PfSenseApiService($firewall);
        $api->setApiTimeout(20);
        $api->createSystemUser([
            'name' => $temp,
            'password' => $password,
            'priv' => ['page-all'],
            'descr' => mb_substr("PFCentral: {$user->email} (temporary, removed when the session ends)", 0, 200),
        ]);

        $jar = new CookieJar();
        try {
            self::signIn($firewall, $jar, $temp, $password);
        } catch (\Throwable $e) {
            self::removeUser($firewall, $temp);
            throw $e;
        }

        $token = Str::random(48);
        $enter = Str::random(48);
        FirewallGuiSession::create([
            'user_id' => $user->id,
            'user_email' => $user->email,
            'firewall_id' => $firewall->id,
            'token_hash' => hash('sha256', $token),
            'enter_hash' => hash('sha256', $enter . '|' . $token),
            'enter_expires_at' => now()->addMinutes(2),
            'ip' => $ip,
            'temp_user' => $temp,
            'cookie_jar' => json_encode($jar->toArray()),
            'actions' => [],
            'started_at' => now(),
            'last_seen_at' => now(),
        ]);
        Log::info('Firewall web interface opened through PFCentral', ['firewall' => $firewall->id, 'user' => $user->email, 'temp' => $temp]);
        return 'https://' . $host . self::PREFIX . '/enter/' . $enter . '.' . $token;
    }

    /** Sign in to the pfSense web interface with the temporary account. */
    private static function signIn(Firewall $firewall, CookieJar $jar, string $user, string $password): void
    {
        $base = rtrim($firewall->url, '/');
        $page = self::client($firewall, $jar)->get($base . '/');
        if (!preg_match('/name=[\'"]__csrf_magic[\'"]\s+value=[\'"]([^\'"]+)[\'"]/', (string) $page->body(), $m)) {
            throw new RuntimeException('The firewall\'s sign-in page didn\'t load as expected.');
        }
        $res = self::client($firewall, $jar)->withHeaders(['Referer' => $base . '/'])->asForm()->post($base . '/index.php', [
            '__csrf_magic' => html_entity_decode($m[1]),
            'usernamefld' => $user,
            'passwordfld' => $password,
            'login' => 'Sign In',
        ]);
        if (!($res->status() === 302 || ($res->successful() && !str_contains((string) $res->body(), 'name="usernamefld"')))) {
            throw new RuntimeException('The firewall didn\'t accept PFCentral\'s temporary sign-in.');
        }
    }

    private static function client(Firewall $firewall, CookieJar $jar): \Illuminate\Http\Client\PendingRequest
    {
        return Http::withOptions(FirewallHttpOptions::get($firewall->tls_public_key_pin, $firewall->url) + ['cookies' => $jar, 'allow_redirects' => false])
            ->timeout(90);
    }

    /** Requests on the web interface host name. */
    public static function handle(Request $request): Response
    {
        // The path exactly as the browser sent it ("/index.php" stays "/index.php", whatever the web server set as the script).
        $path = '/' . ltrim((string) parse_url($request->getRequestUri(), PHP_URL_PATH), '/');
        if (str_starts_with($path, self::PREFIX . '/enter/')) {
            return self::enter($request, substr($path, strlen(self::PREFIX . '/enter/')));
        }

        $session = self::current($request);
        if (!$session) {
            return self::page('Open the firewall from PFCentral', 'This session ended or was never opened here. Open the firewall again from its page in PFCentral.', 401);
        }
        if ($path === self::PREFIX . '/end' || ($path === '/index.php' && $request->query->has('logout'))) {
            self::end($session, 'signed out');
            return self::page('Session ended', 'The temporary firewall account was removed. You can close this tab.', 200, true);
        }
        return self::forward($request, $session, $path);
    }

    private static function enter(Request $request, string $code): Response
    {
        [$enter, $token] = array_pad(explode('.', $code, 2), 2, '');
        $session = $token !== '' ? FirewallGuiSession::where('token_hash', hash('sha256', $token))->first() : null;
        if (!$session || !$session->enter_hash || !hash_equals($session->enter_hash, hash('sha256', $enter . '|' . $token))
            || !$session->enter_expires_at || $session->enter_expires_at->isPast() || $session->ended_at) {
            return self::page('This link was already used', 'Open the firewall again from its page in PFCentral.', 403);
        }
        $session->update(['enter_hash' => null, 'enter_expires_at' => null, 'last_seen_at' => now()]);
        $cookie = \Symfony\Component\HttpFoundation\Cookie::create(self::COOKIE, $token, 0, '/', null, true, true, false, 'lax');
        $res = new \Illuminate\Http\RedirectResponse('/');
        $res->headers->setCookie($cookie);
        return self::secure($res);
    }

    private static function current(Request $request): ?FirewallGuiSession
    {
        $token = (string) $request->cookies->get(self::COOKIE, '');
        if ($token === '') {
            return null;
        }
        $s = FirewallGuiSession::with('firewall')->where('token_hash', hash('sha256', $token))->first();
        if (!$s || $s->ended_at || !$s->firewall) {
            return null;
        }
        if ($s->last_seen_at && $s->last_seen_at->lt(now()->subMinutes(SecuritySettings::guiIdleMinutes()))) {
            self::end($s, 'idle');
            return null;
        }
        if ($s->started_at->lt(now()->subHours(SecuritySettings::GUI_MAX_HOURS))) {
            self::end($s, 'time limit');
            return null;
        }
        if ($s->user_id && (!$s->user || !(\App\Services\Upgrades\Firmware::canUpgrade($s->user, $s->firewall)))) {
            self::end($s, 'access removed');
            return null;
        }
        return $s;
    }

    private static function forward(Request $request, FirewallGuiSession $s, string $path): Response
    {
        $fw = $s->firewall;
        $base = rtrim($fw->url, '/');
        $jarBefore = (string) $s->cookie_jar;
        $jar = new CookieJar(false, json_decode($jarBefore, true) ?: []);

        $headers = ['Referer' => $base . '/'];
        if ($ref = $request->headers->get('referer')) {
            $refPath = parse_url($ref, PHP_URL_PATH) . (($q = parse_url($ref, PHP_URL_QUERY)) ? '?' . $q : '');
            $headers['Referer'] = $base . ($refPath ?: '/');
        }
        if ($request->headers->has('origin')) {
            $headers['Origin'] = $base;
        }
        foreach (['accept', 'accept-language', 'x-requested-with', 'content-type', 'if-modified-since', 'if-none-match', 'range'] as $h) {
            if ($request->headers->has($h)) {
                $headers[$h] = $request->headers->get($h);
            }
        }
        $q = (string) parse_url($request->getRequestUri(), PHP_URL_QUERY);
        $url = $base . $path . ($q !== '' ? '?' . $q : '');
        $method = strtoupper($request->getMethod());
        $client = self::client($fw, $jar)->withHeaders($headers);

        try {
            if (in_array($method, ['GET', 'HEAD'], true)) {
                $res = $client->send($method, $url);
            } elseif (str_starts_with(strtolower((string) $request->headers->get('content-type')), 'multipart/form-data')) {
                unset($headers['content-type']);
                $multi = self::client($fw, $jar)->withHeaders($headers);
                foreach ($request->allFiles() as $name => $file) {
                    foreach ((array) $file as $f) {
                        $multi = $multi->attach($name, fopen($f->getRealPath(), 'r'), $f->getClientOriginalName());
                    }
                }
                $res = $multi->asMultipart()->send($method, $url, ['multipart' => self::fields($request->request->all())]);
            } else {
                $res = $client->withBody((string) $request->getContent(), (string) $request->headers->get('content-type', 'application/x-www-form-urlencoded'))->send($method, $url);
            }
        } catch (\Throwable $e) {
            Log::notice('Firewall web interface proxy: request failed', ['session' => $s->id, 'error' => $e->getMessage()]);
            return self::page('The firewall didn\'t answer', 'PFCentral couldn\'t reach the firewall. Try again in a moment.', 502);
        }

        $jarAfter = json_encode($jar->toArray());
        $update = ['last_seen_at' => now(), 'requests' => $s->requests + 1];
        if ($jarAfter !== $jarBefore) {
            $update['cookie_jar'] = $jarAfter;
        }
        if (!in_array($method, ['GET', 'HEAD', 'OPTIONS'], true)) {
            $actions = $s->actions ?? [];
            $actions[] = ['at' => now()->toIso8601String(), 'method' => $method, 'page' => mb_substr($path, 0, 120)];
            $update['actions'] = array_slice($actions, -self::MAX_ACTIONS);
            // A change made in the firewall's own pages: PFCentral's pages read it fresh.
            \App\Services\FirewallApiCache::forget($s->firewall_id);
        }
        $s->forceFill($update)->save();

        $body = (string) $res->body();
        $out = new Response($body, $res->status());
        foreach (['content-type', 'content-disposition', 'cache-control', 'pragma', 'expires', 'last-modified', 'etag', 'x-frame-options', 'content-security-policy'] as $h) {
            if ($v = $res->header($h)) {
                $out->headers->set($h, $v);
            }
        }
        if ($loc = $res->header('location')) {
            $out->headers->set('Location', self::localLocation($loc, $fw->url));
        }
        if ($res->status() === 200 && str_contains(strtolower((string) $res->header('content-type')), 'text/html') && stripos($body, '</body>') !== false) {
            $out->setContent(self::withBar($body, $s));
        }
        return self::secure($out);
    }

    /** Nested form fields → Guzzle multipart parts. */
    private static function fields(array $data, string $prefix = ''): array
    {
        $parts = [];
        foreach ($data as $k => $v) {
            $name = $prefix === '' ? (string) $k : "{$prefix}[{$k}]";
            if (is_array($v)) {
                $parts = array_merge($parts, self::fields($v, $name));
            } else {
                $parts[] = ['name' => $name, 'contents' => (string) $v];
            }
        }
        return $parts;
    }

    /** A redirect to the firewall's own address stays on PFCentral's host. */
    public static function localLocation(string $location, string $firewallUrl): string
    {
        $fwHost = strtolower((string) parse_url($firewallUrl, PHP_URL_HOST));
        $host = strtolower((string) parse_url($location, PHP_URL_HOST));
        if ($host === '' || $host === $fwHost) {
            $p = parse_url($location, PHP_URL_PATH) ?: '/';
            $q = parse_url($location, PHP_URL_QUERY);
            return ($p[0] === '/' ? $p : '/' . $p) . ($q ? '?' . $q : '');
        }
        return '/';
    }

    private static function withBar(string $html, FirewallGuiSession $s): string
    {
        $who = e($s->user_email);
        $fw = e($s->firewall->name);
        $bar = '<div style="position:fixed;right:12px;bottom:12px;z-index:2147483647;display:flex;gap:10px;align-items:center;'
            . 'padding:7px 8px 7px 12px;border-radius:999px;background:#0E2238;color:#fff;font:500 12px/1.2 system-ui,sans-serif;box-shadow:0 4px 14px rgba(14,34,56,.35)">'
            . '<span style="width:7px;height:7px;border-radius:50%;background:#33C5F3"></span>'
            . "<span>PFCentral · {$fw} · {$who}</span>"
            . '<a href="' . self::PREFIX . '/end" style="color:#0E2238;background:#33C5F3;border-radius:999px;padding:4px 10px;text-decoration:none;font-weight:600">End session</a></div>';
        return preg_replace('#</body>#i', $bar . '</body>', $html, 1);
    }

    private static function secure(Response $res): Response
    {
        $res->headers->set('Referrer-Policy', 'same-origin');
        $res->headers->set('X-Content-Type-Options', 'nosniff');
        $res->headers->set('Strict-Transport-Security', 'max-age=31536000');
        if (!$res->headers->has('Cache-Control')) {
            $res->headers->set('Cache-Control', 'no-store, private');
        }
        return $res;
    }

    private static function page(string $title, string $text, int $status, bool $clearCookie = false): Response
    {
        $html = '<!doctype html><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>' . e($title) . '</title>'
            . '<body style="margin:0;min-height:100vh;display:grid;place-items:center;background:#0E2238;font-family:system-ui,sans-serif">'
            . '<div style="max-width:420px;margin:24px;padding:28px;border-radius:14px;background:#fff;color:#0E2238">'
            . '<div style="font-size:12px;font-weight:600;color:#0A84BA">PFCentral</div><h1 style="margin:8px 0 6px;font-size:20px">' . e($title) . '</h1>'
            . '<p style="margin:0;color:#475569;line-height:1.5">' . e($text) . '</p></div></body>';
        $res = new Response($html, $status, ['Content-Type' => 'text/html; charset=utf-8']);
        if ($clearCookie) {
            $res->headers->clearCookie(self::COOKIE, '/', null, true, true, 'lax');
        }
        return self::secure($res);
    }

    /** End a session: the firewall account is removed now (or by the clean-up if the firewall doesn't answer). */
    public static function end(FirewallGuiSession $s, string $reason): void
    {
        if (!$s->ended_at) {
            $s->update(['ended_at' => now(), 'end_reason' => $reason, 'cookie_jar' => null, 'enter_hash' => null]);
        }
        if (!$s->temp_user_removed && $s->firewall) {
            if (self::removeUser($s->firewall, $s->temp_user)) {
                $s->update(['temp_user_removed' => true]);
            }
        }
    }

    /** Remove the temporary account from the firewall. */
    public static function removeUser(Firewall $firewall, string $name): bool
    {
        try {
            $api = new PfSenseApiService($firewall);
            $api->setApiTimeout(20);
            foreach (($api->getSystemUsers()['data'] ?? []) as $i => $u) {
                if (($u['name'] ?? null) === $name) {
                    $api->deleteSystemUser((string) ($u['id'] ?? $i));
                    return true;
                }
            }
            return true; // already gone
        } catch (\Throwable $e) {
            Log::notice('Firewall web interface: removing the temporary account failed; retrying later', ['firewall' => $firewall->id, 'user' => $name, 'error' => $e->getMessage()]);
            return false;
        }
    }

    /** End idle and over-long sessions and retry removing leftover accounts (every 5 minutes). */
    public static function cleanUp(): int
    {
        $n = 0;
        $idle = now()->subMinutes(SecuritySettings::guiIdleMinutes());
        $max = now()->subHours(SecuritySettings::GUI_MAX_HOURS);
        foreach (FirewallGuiSession::with('firewall')->whereNull('ended_at')->where(fn ($q) => $q->where('last_seen_at', '<', $idle)->orWhere('started_at', '<', $max))->get() as $s) {
            self::end($s, $s->started_at->lt($max) ? 'time limit' : 'idle');
            $n++;
        }
        foreach (FirewallGuiSession::with('firewall')->whereNotNull('ended_at')->where('temp_user_removed', false)->where('ended_at', '>', now()->subDays(7))->limit(50)->get() as $s) {
            self::end($s, (string) $s->end_reason);
        }
        return $n;
    }
}
