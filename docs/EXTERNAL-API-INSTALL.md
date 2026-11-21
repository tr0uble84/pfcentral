# Install the external inventory and status API

PFCentral's external inventory and status API, by **Jay Behi**. This guide uses
example paths and IDs only.

## Requirements and backup

Use an already working PFCentral installation, its supported PHP runtime,
Composer, and the existing database/cache. The lockfile includes Sanctum 4 and
patched CommonMark/HTTP Foundation dependencies. Run Composer as the application
owner, never root. Match the PHP version to the lockfile; development dependencies
and the API CI job use PHP 8.4 or newer.

Get the source:

```bash
git clone https://github.com/tr0uble84/pfcentral.git pfcentral
```

The in-app updater installs GitHub Releases of the repository set in
`GITHUB_REPOSITORY` (default `tr0uble84/pfcentral`).

Before updating an existing installation:

1. Back up application files and the database. Preserve the existing `.env`,
   `APP_KEY`, firewall credentials, certificates, storage and local customizations.
2. Verify the backup and record the current Git commit and local changes privately.
3. Test the update in a separate application copy and disposable database first.
4. Check that `personal_access_tokens` does not already exist from a different
   Sanctum integration. If it does, review and adapt the schema explicitly; do not
   run this migration over an existing table or drop tokens to resolve a conflict.

## Dependencies and token table

The following example assumes `/var/www/pfcentral` is owned by `www-data`.
Replace those values for your installation. These are application-owner commands;
get or update the source before running them.

```bash
cd /var/www/pfcentral
sudo -u www-data composer install --no-dev --no-scripts --no-plugins --no-interaction --prefer-dist
sudo -u www-data php artisan package:discover
sudo -u www-data php artisan migrate --path=database/migrations/2026_10_24_000001_create_personal_access_tokens_table.php --force
```

For an existing installation, use the explicit migration path above so unrelated
pending migrations are not run. For a completely new installation, follow the
original application setup/migration instructions first. Do not run
`artisan install:api` or publish a second Sanctum token migration: PFCentral already
includes its routes, configuration, custom token model and migration.

If your deployment caches config/routes, rebuild those caches through your normal
deployment process after installing the code. Reload long-running PHP processes
as appropriate. Verify `/login` and the existing firewall management pages.
The API deliberately does not alter Fortify browser authentication.

## HTTPS, authentication and scope

Serve the application over HTTPS with a valid certificate. Configure only your
actual trusted reverse proxy when TLS terminates upstream; do not trust arbitrary
forwarded headers. This API rejects plain HTTP and never uses browser cookies as
credentials. Tokens belong in the consumer backend's secret manager.

Use existing numeric user/company IDs, not names. Example ID 1 below is a placeholder:

```bash
# Read-only access to one company, 30-day lifetime.
sudo -u www-data php artisan integration:token:issue --user=1 --company=1 --name=company-integration --days=30

# All companies: the owner must be a current global administrator.
sudo -u www-data php artisan integration:token:issue --user=1 --all-companies --name=msp-integration --days=30

# Explicitly non-expiring; also restricted to current global administrators.
sudo -u www-data php artisan integration:token:issue --user=1 --all-companies --name=msp-permanent --never-expires
```

The issuer prints only a private file path. Transfer the token securely from that
file, then remove the file. Never paste credentials into issues, discussions,
terminal transcripts, frontend code, example files or source control. The database
stores a token hash. Keep its nonsecret ID for revocation:

```bash
sudo -u www-data php artisan integration:token:revoke TOKEN_ID
```

Finite tokens default to 30 days (maximum 90). Non-expiring tokens require the
explicit ability and remain revocable. Losing global-admin authorization blocks
MSP and non-expiring tokens. Company tokens stay bound to the issued company.

## Background status collection

Inventory endpoints work without a collector. Status endpoints read stored
snapshots only; configure collection to keep telemetry current.

1. Configure a real asynchronous queue, for example `QUEUE_CONNECTION=redis`, and
   the existing Redis connection. Do not use the `sync` driver for fleet dispatch.
2. Use a shared persistent cache in multi-instance deployments so locks, token
   limits and status snapshots are shared. Do not use `array` for production.
3. Start dedicated workers that listen only to `integration-status`:

```bash
sudo -u www-data php artisan queue:work redis --queue=integration-status --sleep=2 --tries=1 --timeout=75 --max-time=3600
```

Use a process manager in production. Adapt
[`deployment/integration-status-supervisor.conf.example`](deployment/integration-status-supervisor.conf.example)
for your path/user/queue. `redis.retry_after` must exceed the 75-second job timeout
(Laravel's usual 90-second value does). Start with two workers, then size concurrency
for fleet size and upstream response time. Do not consume the application's other
queues unintentionally.

Schedule this command once a minute as the application user, using your existing
process manager, systemd timer or cron. Do not install multiple schedules:

```bash
cd /var/www/pfcentral
sudo -u www-data php artisan integration:status:collect
```

For example, the application's user's crontab can contain:

```cron
* * * * * cd /var/www/pfcentral && /usr/bin/php artisan integration:status:collect >/dev/null 2>&1
```

An operator can collect exactly one selected firewall synchronously for diagnostics:

```bash
sudo -u www-data php artisan integration:status:collect --firewall=1 --sync
```

Jobs are deduplicated and guarded by execution locks. Upstream calls are read-only;
the collector does not run shell diagnostics, modify firewall configuration or
auto-enroll TLS pins. Preconfigure trusted certificates/API permissions through
normal application administration. A failed collection produces a generic failure
state, not a raw upstream exception. Optional unavailable sections are listed.

Defaults in `config/integration-api.php`: snapshots retained 24 hours, stale after
300 seconds, 60 requests/minute/token and 120/minute/source IP. Whole-fleet collection
can take longer than one minute; measure age/queue depth and adjust worker capacity.

## Consumer contract

Base path is `https://pfcentral.example.com/api/v1` (reserved example hostname).
Supply `Authorization: Bearer <token>` separately through protected configuration.

| GET path | Purpose |
| --- | --- |
| `/me` | Effective token identity/scope |
| `/companies` | Visible companies |
| `/companies/{companyId}` | One visible company |
| `/firewalls` | Paginated registered inventory |
| `/firewalls/{firewallId}` | One inventory record |
| `/firewalls/status` | Paginated status snapshots |
| `/firewalls/{firewallId}/status` | One status snapshot |
| `/openapi.json` | Authenticated OpenAPI document |

Join status to inventory by numeric `firewall_id`, retaining `company_id`. Follow
`meta.last_page`; use bulk status for fleet refreshes. Render null as unknown,
respect `checked_at`/`stale`/`unavailable_sections`, and retain successive traffic
samples to draw charts. No historical time-series storage or switch/LAN discovery
is provided by these endpoints. Never treat source descriptions as trusted HTML.

See [inventory/token guide](external-api-v1.md),
[status contract](PFCENTRAL-STATUS-API.md), [OpenAPI](openapi-v1.json),
[Postman collection](examples/postman_collection.json) and the
[Python inventory-only example](examples/fetch_inventory.py).

## Testing and rollback

Run tests only in an isolated checkout with a disposable database and test APP_KEY.
`RefreshDatabase` resets the selected database. Never use production `.env` or an
account that can modify production databases. The API CI workflow uses an ephemeral
MySQL service and test credentials only.

```bash
composer install --no-scripts --no-plugins --no-interaction
php artisan package:discover
vendor/bin/phpunit tests/Feature/ExternalIntegrationApiTest.php tests/Feature/ExternalFirewallStatusApiTest.php
```

Before rollback, stop the new collector schedule/workers and revoke integration
tokens if withdrawing the API. Restore the previous source and dependency lockfile
using your backup/deployment procedure; rebuild the application's caches. Keep
the additive token table unless an explicit data-removal plan requires otherwise.
Do not run a general migration rollback, flush shared Redis or restore an old
database over newer production data just to revert this feature.
