# External inventory API v1

Base path: `https://YOUR-PFCENTRAL-HOST/api/v1` on the existing HTTPS web listener.
Use the HTTPS application listener, not the SSH management port.

Send `Authorization: Bearer YOUR_TOKEN` with every request. No password login endpoint
is exposed. Web sessions, CSRF cookies and wildcard-only tokens are not accepted.
Non-expiring tokens require explicit issuance with --never-expires and a current
global-administrator owner. CORS and stateful SPA authentication are not enabled.

## Endpoints

| Method | Path | Response |
| --- | --- | --- |
| GET | /me | Token owner's ID/name, scope, authorized company ID (null for MSP) and abilities |
| GET | /companies | All companies for an MSP token, or the single authorized company for a scoped token |
| GET | /companies/{companyId} | Company ID, name, description and timestamps |
| GET | /firewalls | Paginated stored inventory, ordered by numeric ID |
| GET | /firewalls/{firewallId} | Stored inventory by numeric ID |
| GET | /openapi.json | Authenticated OpenAPI 3.0.3 document |

GET routes also support HEAD. All resource writes return 405. Firewall endpoints
return only id, company_id, name, os_type, netgate_id, description and timestamps.
Descriptions are existing operator-entered text: keep credentials out of descriptions.
Management URLs, SSH configuration, API credentials, backups and system settings
are excluded. These endpoints make no requests to managed firewalls.

Pagination uses `?page=1&per_page=25`; per_page is at most 100 and page at most 1000000.
An MSP token can optionally filter firewall inventory using `?company_id=1`.
Without that filter it returns inventory across all companies. Follow every page to
retrieve the full inventory. A company-scoped token cannot select another company.
The response contains `data` and `meta` (current_page, per_page, last_page, total).
Other responses use `data`, except the raw OpenAPI document.

Company-scoped tokens remain bound to their issued company, even for global admins.
An explicit MSP token has both `inventory:read` and `inventory:read:all`, a null
company_id, and must belong to a current global administrator. It accesses every
company, including companies created later. It fails immediately if its owner loses
global-admin status. Company admins and ordinary users cannot issue or use MSP tokens.
A non-global owner of a scoped token must
still match; removed users and unsupported roles fail authorization. Moving a user
does not move their tokens. Revoke/reissue tokens after changing access requirements.

Status codes: 401 invalid authentication; 403 unauthorized scope or plain HTTP;
404 missing or other-company resource; 405 unsupported method; 422 invalid pagination;
429 rate limit (with Retry-After); 500 generic server error; 503 setup incomplete.
Default limits: 60/minute per token, plus 120/minute per source IP across tokens.
Use a shared cache for multiple application instances. Behind a proxy, configure
Laravel trusted proxies for the actual proxy only; never blindly trust forwarded headers.

## Issue and revoke tokens

Use an existing, preferably dedicated readonly user belonging to the target company.
For MSP-wide access, select an existing global administrator. Example IDs below are placeholders.
Only administrators with server CLI access can issue tokens; there is no public
HTTP token-creation endpoint. The user's browser password and 2FA remain unchanged.
Tokens are independent machine credentials and must be protected accordingly.

```bash
cd /var/www/pfcentral
sudo -u www-data php artisan integration:token:issue --user=USER_ID --company=COMPANY_ID --name=INTEGRATION_NAME --days=30
```

For a single token covering the full MSP inventory:

```bash
sudo -u www-data php artisan integration:token:issue --user=1 --all-companies --name=MSP-inventory --days=30
```

Use exactly one of --company and --all-companies. Both modes are read-only.

For a read-only MSP token with no expiration date:

```bash
sudo -u www-data php artisan integration:token:issue --user=1 --all-companies --name=MSP-inventory-permanent --never-expires
```

Use either --days or --never-expires, never both. The default remains 30 days.
Only a global administrator may own a non-expiring token; loss of that role blocks
it immediately. These tokens have the explicit inventory:never-expires ability and
expires_at=null. A null expiry without that ability is still rejected. Non-expiring
tokens can be revoked using the same token:revoke command at any time.

Finite lifetime is 1-90 days. The command prints only the path of a private JSON file under
`storage/app/private/integration-tokens/` (directory 0700, file 0600).
Transfer its token securely to the integration, then remove the JSON file.
Never paste it into chat or place it in a URL. The database stores only its SHA-256 hash.
Retain its nonsecret token_id for revocation. Expired tokens fail immediately even if
their database rows have not yet been pruned.

```bash
sudo -u www-data php artisan integration:token:revoke TOKEN_ID
```

Rotate by issuing a new token, updating the integration, testing, then revoking the old
token. Non-expiring tokens remain valid until revoked, their owner is removed or loses
global-admin status, or their authorization is otherwise removed.
Sanctum updates last_used_at during successful authentication. No new request
logger records bearer tokens. Existing web-server/APM logging should not log Authorization.

## Validation and rollback

Run PHPUnit only in a separate copy with a disposable database and test APP_KEY;
never run database-resetting tests against production. ExternalIntegrationApiTest covers
real bearer authentication, company isolation, credentials, token lifecycle, pagination,
HTTPS, rate limits, documentation, CLI commands and HTTP method restrictions.

Deployment runs only the new personal_access_tokens migration by explicit path;
it does not execute unrelated pending application migrations. The original web routes,
Fortify configuration and firewall-side REST API management routes are retained.

For application rollback, first restore the changed source/config/bootstrap files from
the recorded pre-deployment backup, remove the API-only files recorded in its manifest,
then run package discovery as www-data. Stop using and revoke integration tokens first.
Leaving the additive personal_access_tokens table in place is safer than dropping it.
Do not run a general migrate:rollback, and do not restore the entire old database over
new production data. Create and verify a fresh application/database backup before installation. Keep
your backup location and rollback record private; they are not part of this repository.

## Firewall telemetry and installation

API 1.2 adds GET `/firewalls/status` and GET `/firewalls/{firewallId}/status`,
using the same authorization. See [status contract](PFCENTRAL-STATUS-API.md)
and [installation guide](EXTERNAL-API-INSTALL.md). External API by **Jay Behi**.
