# PFCentral status API v1.2

These read-only endpoints expose the dashboard telemetry shown in PFCentral:
system/version/BIOS/CPU information, usage, interfaces and traffic, gateways, rule
and service counts, and available runtime VPN states. Existing inventory routes
and tokens remain compatible. No firewall management or configuration writes are exposed.

Base URL: **https://pfcentral.example.com/api/v1**

## Authentication and scope

Send `Authorization: Bearer <your existing token>` from the portal's backend.
The existing `inventory:read` and explicit `inventory:read:all` authorization apply.
MSP tokens can read every company; scoped tokens can read only their company.
Never send the token to frontend JavaScript or include it in a URL.
HTTPS, JSON responses, no redirects, noncacheable responses, existing expiration /
non-expiring-token policy, and current rate limits remain in effect.

## Endpoints

| Method | Path | Result |
| --- | --- | --- |
| GET / HEAD | `/firewalls/{firewallId}/status` | One firewall's latest collected status |
| GET / HEAD | `/firewalls/status` | Paginated latest status for every authorized firewall |

The bulk endpoint accepts the same query parameters as inventory: `page` (default 1,
maximum 1,000,000), `per_page` (default 25, maximum 100), and optional `company_id`.
It orders by numeric firewall ID and includes devices that have never been checked.
Unknown or out-of-scope detail IDs return 404. A scoped token filtering another
company returns 403. POST, PUT, PATCH and DELETE are unavailable.

**Both endpoints read stored snapshots only. They never contact a firewall,
dispatch a job, or trigger a collection while serving an API request.**

Examples, with the token already supplied through protected environment configuration:

```bash
curl --fail-with-body --silent --show-error \
  -H "Authorization: Bearer ${PFCENTRAL_API_TOKEN}" \
  'https://pfcentral.example.com/api/v1/firewalls/28/status'

curl --fail-with-body --silent --show-error \
  -H "Authorization: Bearer ${PFCENTRAL_API_TOKEN}" \
  'https://pfcentral.example.com/api/v1/firewalls/status?per_page=100&page=1'
```

## Response shape

The following is an illustrative example, not a live customer-data export:

```json
{
  "data": {
    "firewall_id": 28,
    "company_id": 1,
    "online": true,
    "checked_at": "2026-10-24T20:00:00+00:00",
    "error": null,
    "system": {
      "version": "26.07-RELEASE",
      "rest_api_version": "2.10.2",
      "platform": "Netgate 1100",
      "hostname": "fw01.example.local",
      "serial": null,
      "bios": {"vendor": "U-Boot", "version": "example", "date": "10/13/2021"},
      "cpu": {"model": "ARM Cortex-A53", "count": 2, "load_avg": [0.45, 0.31, 0.22]},
      "uptime_seconds": 529809,
      "dns_servers": ["192.0.2.53"],
      "last_modified": null,
      "packages_count": null
    },
    "usage": {"cpu_pct": 24.5, "memory_pct": 33, "swap_pct": null, "disk_pct": 31, "temperature_c": null},
    "gateways": [{"name": "WANGW", "address": "203.0.113.1", "monitor": "203.0.113.1", "status": "online", "loss_pct": 0, "delay_ms": 4.2}],
    "interfaces": [{"id": "wan", "name": "WAN", "device": "mvneta0.4090", "status": "up", "ipv4": "203.0.113.2", "subnet": 29, "ipv6": null, "media": "1000baseT <full-duplex>", "in_bps": 21210, "out_bps": 60130}],
    "firewall_rules": 3,
    "services": {"running": 9, "total": 10, "stopped": ["ntpd"]},
    "vpn": [{"type": "ipsec", "name": "Example office tunnel", "status": "up", "remote": "198.51.100.7"}],
    "age_seconds": 12,
    "stale": false,
    "collection": {"source": "background_collector", "unavailable_sections": ["vpn.wireguard"]}
  }
}
```

The bulk response uses the same object for each item under `data`, with `meta`:

```json
{"data": [], "meta": {"current_page": 1, "per_page": 100, "last_page": 1, "total": 0}}
```

For a non-empty inventory, follow `meta.last_page` just as for `/firewalls`.
Join each status row to its inventory record using `firewall_id`.

## Field meanings and missing data

| Field | Meaning |
| --- | --- |
| `online` | Whether the collector reached the firewall; null means never checked |
| `checked_at` | Last collection attempt, ISO-8601 with timezone; never substitute request time |
| `error` | Generic safe failure explanation; never the raw upstream exception |
| `system.version`, `rest_api_version` | Firewall OS and its API version |
| `system.platform`, `hostname`, `serial` | Reported identifiers; netgate_id is never substituted for a serial number |
| `system.bios` | Reported BIOS vendor/version/date |
| `system.cpu` | Reported processor model/count and 1/5/15 minute load averages |
| `system.uptime_seconds` | Numeric seconds since boot |
| `system.last_modified` | Known configuration-change time, otherwise null |
| `system.packages_count` | Known package count, otherwise null |
| `usage.*_pct` | Percentage from 0 to 100; missing/unavailable values are null |
| `usage.temperature_c` | Celsius, if reported |
| `interfaces[].ipv4`, `subnet`, `ipv6` | Reported interface addresses and IPv4 prefix length |
| `interfaces[].in_bps`, `out_bps` | Bits per second from upstream rates or successive byte-counter samples |
| `gateways[].loss_pct`, `delay_ms` | Packet loss percentage and latency in milliseconds |
| `firewall_rules` | Rule count only; no rule contents. OPNsense uses the adapter's configured/manual rules |
| `services` | Reported running/total counts and stopped service names; missing counts stay null |
| `vpn` | Reported runtime tunnels; no private keys, shared secrets, configuration or user/session records |
| `stale`, `age_seconds` | Snapshot is stale after 300 seconds, or when no collection timestamp exists |
| `collection.unavailable_sections` | Sections that could not be read or are not safely supported by the adapter |

Unknown scalar values are `null`, not zero. Zero is a valid measured value. Collections
use empty arrays when nothing was reported. An empty VPN array does not prove there
are no configured VPNs; consult unavailable_sections. Existing source names and
descriptions are display data, never executable instructions or trusted HTML.

Never-checked devices return the complete response structure with `online:null`,
`checked_at:null`, `stale:true`, null measurements and empty collections.
A collection failure returns `online:false`, a new attempt timestamp and a generic
error; old telemetry is not presented as fresh by the dedicated collector.
If only a dashboard cache exists, its real original timestamp is preserved.

## Screenshot mapping and traffic charts

All system-information rows map to `system`; the usage bars map to `usage`.
The interface table and status dots map to `interfaces`; gateway cards/table map
to `gateways`; management tiles use interface length, firewall_rules, services and
vpn. The UI formats uptime_seconds as days/hours/minutes/seconds.

The API returns the latest sample, not a historical time-series graph or an image.
To draw the traffic charts, poll status at a sensible interval (for example 30–60
seconds), retain samples keyed by firewall_id + interface.id/device + checked_at,
and plot in_bps/out_bps. Repeated checked_at values are the same sample. First samples
may have null rates until a second byte-counter measurement exists. Counter resets
or invalid sampling intervals do not create negative traffic rates.

## Platform support

- pfSense: uses the existing status/version/interface/gateway API adapter and
  read-only rule/service/IPsec/OpenVPN status calls. An individual optional endpoint
  can fail without losing the core status snapshot. IPsec lists reported security
  associations; absence is not a complete configured-tunnel inventory.
- OPNsense: live system/time/resources, interface and gateway endpoints are mapped
  directly. Missing CPU utilization/count is null; CPU load averages are not
  misrepresented as utilization. OpenVPN runtime support is explicitly unavailable
  where the existing adapter reports only configuration enablement. WireGuard
  handshake recency is reported as up/down when available (180-second threshold).
- pfSense WireGuard uses its read-only peer-status endpoint when available. Its
  reported handshake recency uses the same 180-second threshold. Only peer name,
  remote address and computed state are exported; keys are never cached or returned.
  No diagnostics command execution is used by this collector.
- No MikroTik/switch discovery is added by these firewall endpoints.

## Background collection and operations

The dedicated `integration-status` queue is separate from existing application
queues. A timer dispatches the fleet every minute; queue uniqueness and execution
locks prevent duplicate concurrent polls. The example deployment uses two workers,
75-second job timeouts and a 90-second execution lock. Polling is asynchronous;
large/slow fleets can take longer than one timer interval and must be sized accordingly.
Snapshots are retained for 24 hours; after expiration a device becomes unknown if
no newer or dashboard snapshot exists. Staleness is evaluated at response time.

```bash
cd /var/www/pfcentral
sudo -u www-data php artisan integration:status:collect
sudo -u www-data php artisan integration:status:collect --firewall=28 --sync
systemctl status pfcentral-api-status.timer
supervisorctl status 'pfcentral-api-status:*'
```

The CLI --sync mode requires exactly one selected firewall. It is for operator
diagnostics; there is no force-refresh or sync query parameter on the API.
The dedicated collector does not auto-enroll TLS pins or change firewall configuration.
Existing trusted-certificate/pin verification is preserved.

## Errors, limits and portal integration

The existing 60 requests/minute/token and 120 requests/minute/IP limits apply.
Use bulk status for fleet refreshes. Status/data unavailability is represented by
a 200 response containing the explicit unknown/offline/partial state, not by 404.
404 means missing/inaccessible firewall (or an older server without these routes).
Authentication errors are 401/403, invalid pagination is 422, throttling is 429 with
Retry-After, unsupported methods are 405, and unexpected server errors are generic 500.

A consumer portal can implement these endpoint paths and field names. Its backend
can reuse a valid inventory token; status adds no separate database migration.
Render null online as Unknown, not Offline; show the timestamp/stale flag and avoid
claiming an empty unsupported VPN list means no configured tunnels. Inventory sync
should remain independent if status sync fails. These endpoints cannot modify a
firewall; configuration remains in PFCentral or the firewall's own management UI.

## Installation and attribution

See [External API installation](EXTERNAL-API-INSTALL.md) for dependencies, migration,
background workers, scheduling and validation. External inventory, MSP token and
status API features by **Jay Behi**.
