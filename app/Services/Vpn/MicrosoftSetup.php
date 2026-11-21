<?php

namespace App\Services\Vpn;

use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * One-click Microsoft 365 setup for a company ("Connect Microsoft 365 automatically").
 *
 * A Global Administrator of the company's Microsoft 365 signs in through CFBTEL's "setup" app
 * (one multi-tenant app registration in CFBTEL's Entra, delegated permissions only). With that
 * short-lived delegated token, PFCentral creates the company's own "CFBTEL VPN" app in the
 * company's tenant, the same way CIPP's SAM wizard does:
 *
 *   POST /applications                                   the app (single tenant, VPN-page redirect URI, permissions)
 *   POST /applications/{id}/addPassword                  a client secret (2 years)
 *   POST /servicePrincipals                              its enterprise application
 *   POST /servicePrincipals/{graph}/appRoleAssignedTo    admin consent for User.Read.All (application)
 *   POST /oauth2PermissionGrants                         admin consent for openid profile email User.Read (delegated)
 *   GET  /organization                                   tenant name and verified email domains
 *
 * Finally it deletes the setup app's own enterprise application from the company's tenant,
 * so CFBTEL keeps no setup access there. The delegated token is never stored.
 */
class MicrosoftSetup
{
    public const GRAPH_APP_ID = '00000003-0000-0000-c000-000000000000';
    public const APP_NAME = 'CFBTEL VPN';
    public const DELEGATED = ['openid', 'profile', 'email', 'User.Read'];
    public const APPLICATION = ['User.Read.All', 'GroupMember.Read.All'];
    public const SETUP_SCOPES = [
        'https://graph.microsoft.com/Application.ReadWrite.All',
        'https://graph.microsoft.com/AppRoleAssignment.ReadWrite.All',
        'https://graph.microsoft.com/DelegatedPermissionGrant.ReadWrite.All',
        'https://graph.microsoft.com/User.Read', // reading the organization's name and domains needs it
    ];
    public const SECRET_YEARS = 2;

    /** Tests swap in a fake. @var (\Closure(): MicrosoftSetup)|null */
    public static ?\Closure $factory = null;

    /** Pause between retries while Microsoft replicates a new app (tests set a no-op). @var (\Closure(int): void)|null */
    public static ?\Closure $sleep = null;

    private ?string $token = null;

    public function __construct(private ?string $clientId = null, private ?string $clientSecret = null)
    {
        $this->clientId ??= VpnSettings::get('setup_client_id');
        $this->clientSecret ??= VpnSettings::secret('setup_client_secret');
    }

    public static function make(): self
    {
        return self::$factory ? (self::$factory)() : new self();
    }

    public function configured(): bool
    {
        return (bool) ($this->clientId && $this->clientSecret);
    }

    public function clientId(): ?string
    {
        return $this->clientId;
    }

    /** Where Microsoft sends the admin back; register it on the setup app (Web platform). */
    public static function redirectUri(): string
    {
        return VpnSettings::portalUrl('/vpn/setup/callback');
    }

    private static function b64u(string $bin): string
    {
        return rtrim(strtr(base64_encode($bin), '+/', '-_'), '=');
    }

    /** @return array{url: string, pending: array{state: string, nonce: string, verifier: string}} */
    public function authorizeUrl(?string $loginHint = null): array
    {
        if (!$this->configured()) {
            throw new VpnException('One-click setup isn\'t configured yet (VPN settings: CFBTEL setup app).');
        }
        $pending = ['state' => self::b64u(random_bytes(18)), 'nonce' => self::b64u(random_bytes(18)), 'verifier' => self::b64u(random_bytes(32))];
        $query = http_build_query([
            'client_id' => $this->clientId,
            'response_type' => 'code',
            'redirect_uri' => self::redirectUri(),
            'response_mode' => 'query',
            'scope' => 'openid profile ' . implode(' ', self::SETUP_SCOPES),
            'state' => $pending['state'],
            'nonce' => $pending['nonce'],
            'code_challenge' => self::b64u(hash('sha256', $pending['verifier'], true)),
            'code_challenge_method' => 'S256',
        ] + ($loginHint ? ['login_hint' => $loginHint] : ['prompt' => 'select_account']));
        return ['url' => Microsoft::AUTHORITY . "/organizations/oauth2/v2.0/authorize?{$query}", 'pending' => $pending];
    }

    /** Exchange the code for the admin's delegated Graph token. @return array{tid: string, upn: string, name: string} */
    public function redeem(string $code, array $pending): array
    {
        if (!$this->configured()) {
            throw new VpnException('One-click setup isn\'t configured yet.');
        }
        $res = Http::asForm()->timeout(20)->post(Microsoft::AUTHORITY . '/organizations/oauth2/v2.0/token', [
            'client_id' => $this->clientId,
            'client_secret' => $this->clientSecret,
            'grant_type' => 'authorization_code',
            'code' => $code,
            'redirect_uri' => self::redirectUri(),
            'code_verifier' => $pending['verifier'],
            'scope' => 'openid profile ' . implode(' ', self::SETUP_SCOPES),
        ]);
        $idToken = $res->json('id_token');
        $access = $res->json('access_token');
        if (!$res->successful() || !is_string($idToken) || !is_string($access)) {
            throw new VpnException(strtok((string) ($res->json('error_description') ?? 'Microsoft sign-in failed.'), "\r\n"));
        }
        $who = (new Microsoft($this->clientId, $this->clientSecret, null))->verifyIdToken($idToken, $pending['nonce']);
        $this->token = $access;
        return ['tid' => $who['tid'], 'upn' => $who['upn'], 'name' => $who['name']];
    }

    /** For tests and for a token obtained elsewhere. */
    public function withToken(string $token): self
    {
        $this->token = $token;
        return $this;
    }

    private function call(string $method, string $path, ?array $body = null): Response
    {
        if (!$this->token) {
            throw new VpnException('Not signed in to Microsoft.');
        }
        $req = Http::withToken($this->token)->acceptJson()->timeout(25);
        $url = Microsoft::GRAPH . '/v1.0' . $path;
        return match ($method) {
            'get' => $req->get($url),
            'delete' => $req->delete($url),
            'patch' => $req->patch($url, $body ?? []),
            default => $req->post($url, $body ?? []),
        };
    }

    private static function fail(Response $res, string $what): VpnException
    {
        $code = (string) $res->json('error.code', '');
        $msg = (string) $res->json('error.message', "Microsoft Graph error {$res->status()}");
        if ($res->status() === 403 || $code === 'Authorization_RequestDenied') {
            return new VpnException("Microsoft refused to {$what}: sign in as a Global Administrator of the company's Microsoft 365 and accept the permissions. ({$msg})");
        }
        return new VpnException("Couldn't {$what}: {$msg}");
    }

    /** Graph needs a moment after creating an app before other calls can see it: retry those. */
    private function callRetrying(string $method, string $path, ?array $body, string $what): Response
    {
        for ($try = 1; ; $try++) {
            $res = $this->call($method, $path, $body);
            if ($res->successful()) {
                return $res;
            }
            $notYet = $res->status() === 404
                || ($res->status() === 400 && preg_match('/does not reference a valid application|does not exist|ResourceNotFound/i', (string) $res->json('error.message', '')));
            if (!$notYet || $try >= 6) {
                throw self::fail($res, $what);
            }
            (self::$sleep ?? fn (int $s) => sleep($s))(min(2 * $try, 6));
        }
    }

    /** Tenant name and verified domains. @return array{name: ?string, domains: string[]} */
    public function organization(): array
    {
        $res = $this->call('get', '/organization?$select=id,displayName,verifiedDomains');
        if (!$res->successful()) {
            throw self::fail($res, 'read the organization');
        }
        $org = $res->json('value.0') ?? [];
        $domains = collect($org['verifiedDomains'] ?? [])->pluck('name')->map(fn ($d) => strtolower((string) $d))->filter()->unique()->values()->all();
        return ['name' => $org['displayName'] ?? null, 'domains' => $domains];
    }

    /** Microsoft Graph's enterprise application in this tenant with its permission IDs. */
    private function graphServicePrincipal(): array
    {
        $res = $this->call('get', "/servicePrincipals(appId='" . self::GRAPH_APP_ID . "')?\$select=id,appRoles,oauth2PermissionScopes");
        if (!$res->successful()) {
            throw self::fail($res, 'find Microsoft Graph in the tenant');
        }
        $sp = $res->json();
        $scopes = collect($sp['oauth2PermissionScopes'] ?? [])->pluck('id', 'value');
        $roles = collect($sp['appRoles'] ?? [])->filter(fn ($r) => in_array('Application', $r['allowedMemberTypes'] ?? [], true))->pluck('id', 'value');
        $ids = ['scopes' => [], 'roles' => []];
        foreach (self::DELEGATED as $v) {
            $ids['scopes'][$v] = $scopes[$v] ?? throw new VpnException("Microsoft Graph has no {$v} permission in this tenant.");
        }
        foreach (self::APPLICATION as $v) {
            $ids['roles'][$v] = $roles[$v] ?? throw new VpnException("Microsoft Graph has no {$v} permission in this tenant.");
        }
        return ['id' => $sp['id'], 'ids' => $ids];
    }

    /**
     * Create (or update) the company's "CFBTEL VPN" app, give it a new secret and admin consent.
     *
     * @param string|null $existingAppId the company's app from an earlier setup, reused when it still exists
     * @return array{client_id: string, client_secret: string, object_id: string, key_id: string, expires_at: \Carbon\CarbonImmutable, reused: bool}
     */
    public function provision(string $redirectUri, ?string $existingAppId = null, string $appName = self::APP_NAME, ?string $notes = null): array
    {
        $graph = $this->graphServicePrincipal();
        $required = [[
            'resourceAppId' => self::GRAPH_APP_ID,
            'resourceAccess' => array_merge(
                array_map(fn ($id) => ['id' => $id, 'type' => 'Scope'], array_values($graph['ids']['scopes'])),
                array_map(fn ($id) => ['id' => $id, 'type' => 'Role'], array_values($graph['ids']['roles'])),
            ),
        ]];

        $app = null;
        if ($existingAppId && Microsoft::isGuid($existingAppId)) {
            $res = $this->call('get', "/applications(appId='{$existingAppId}')?\$select=id,appId,web");
            if ($res->successful()) {
                $app = $res->json();
            } elseif ($res->status() !== 404) {
                throw self::fail($res, 'read the existing VPN app');
            }
        }
        $reused = $app !== null;
        if ($reused) {
            $uris = array_values(array_unique(array_merge($app['web']['redirectUris'] ?? [], [$redirectUri])));
            $res = $this->call('patch', "/applications/{$app['id']}", ['web' => ['redirectUris' => $uris], 'requiredResourceAccess' => $required]);
            if (!$res->successful()) {
                throw self::fail($res, 'update the VPN app');
            }
        } else {
            $res = $this->call('post', '/applications', [
                'displayName' => $appName,
                'signInAudience' => 'AzureADMyOrg',
                'notes' => $notes ?? 'Created by PFCentral for the CFBTEL staff VPN sign-in. Deleting it stops VPN sign-in with Microsoft 365.',
                'web' => ['redirectUris' => [$redirectUri]],
                'requiredResourceAccess' => $required,
            ]);
            if (!$res->successful()) {
                throw self::fail($res, 'create the VPN app');
            }
            $app = $res->json();
        }

        $expires = \Carbon\CarbonImmutable::now()->addYears(self::SECRET_YEARS)->startOfSecond();
        $pw = $this->callRetrying('post', "/applications/{$app['id']}/addPassword", [
            'passwordCredential' => ['displayName' => $appName === self::APP_NAME ? 'PFCentral VPN' : 'PFCentral', 'endDateTime' => $expires->toIso8601ZuluString()],
        ], 'create the app secret')->json();
        if (empty($pw['secretText']) || empty($pw['keyId'])) {
            throw new VpnException('Microsoft didn\'t return the app secret.');
        }

        // Its enterprise application (service principal); it may already exist when the app is reused.
        $res = $this->call('get', "/servicePrincipals(appId='{$app['appId']}')?\$select=id");
        $spId = $res->successful() ? $res->json('id') : null;
        if (!$spId) {
            $spId = $this->callRetrying('post', '/servicePrincipals', ['appId' => $app['appId']], 'create the enterprise application')->json('id');
        }

        // Admin consent, application permission: User.Read.All (skipped when already granted).
        $res = $this->call('get', "/servicePrincipals/{$spId}/appRoleAssignments?\$select=appRoleId,resourceId");
        $have = $res->successful() ? collect($res->json('value') ?? [])->where('resourceId', $graph['id'])->pluck('appRoleId')->all() : [];
        foreach ($graph['ids']['roles'] as $name => $roleId) {
            if (in_array($roleId, $have, true)) {
                continue;
            }
            $this->callRetrying('post', "/servicePrincipals/{$graph['id']}/appRoleAssignedTo", [
                'principalId' => $spId, 'resourceId' => $graph['id'], 'appRoleId' => $roleId,
            ], "grant {$name}");
        }

        // Admin consent, delegated permissions for everyone in the organization.
        $scope = implode(' ', self::DELEGATED);
        $res = $this->call('get', "/oauth2PermissionGrants?\$filter=" . rawurlencode("clientId eq '{$spId}' and resourceId eq '{$graph['id']}' and consentType eq 'AllPrincipals'"));
        $grant = $res->successful() ? ($res->json('value.0') ?? null) : null;
        if ($grant) {
            $res = $this->call('patch', "/oauth2PermissionGrants/{$grant['id']}", ['scope' => $scope]);
            if (!$res->successful()) {
                throw self::fail($res, 'grant sign-in permissions');
            }
        } else {
            $this->callRetrying('post', '/oauth2PermissionGrants', [
                'clientId' => $spId, 'consentType' => 'AllPrincipals', 'resourceId' => $graph['id'], 'scope' => $scope,
            ], 'grant sign-in permissions');
        }

        return [
            'client_id' => strtolower($app['appId']),
            'client_secret' => $pw['secretText'],
            'object_id' => strtolower($app['id']),
            'key_id' => strtolower($pw['keyId']),
            'expires_at' => $expires,
            'reused' => $reused,
        ];
    }

    /** Best effort: remove an old secret of the company's app after the new one is saved. */
    public function removeSecret(string $appObjectId, string $keyId): void
    {
        try {
            $this->call('post', "/applications/{$appObjectId}/removePassword", ['keyId' => $keyId]);
        } catch (\Throwable) {
        }
    }

    /** Best effort: delete the setup app's enterprise application (and with it, its permissions) from the company's tenant. */
    public function removeSetupAccess(): bool
    {
        try {
            $res = $this->call('get', "/servicePrincipals(appId='{$this->clientId}')?\$select=id");
            $id = $res->successful() ? $res->json('id') : null;
            return $id && $this->call('delete', "/servicePrincipals/{$id}")->successful();
        } catch (\Throwable) {
            return false;
        }
    }
}
