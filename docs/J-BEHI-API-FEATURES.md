# External API

PFCentral's read-only external integration API: inventory, MSP tokens, the
non-expiring-token option and background firewall status, by **Jay Behi**.
Laravel Sanctum and other dependencies keep their own licenses and authorship.

## Included features

- Company-scoped bearer access and explicitly authorized MSP access across companies.
- Safe inventory field lists, pagination, HTTPS enforcement, JSON failures,
  per-token/source-IP rate limits and noncacheable authenticated responses.
- CLI-only private token issuance/revocation, finite lifetimes by default and an
  explicit non-expiring option restricted to current global administrators.
- Per-firewall and bulk status snapshots: available system details, CPU/memory/disk,
  interfaces/traffic, gateways, service/rule counts and runtime VPN status.
- Independent asynchronous collection with deduplication/locks, timestamps,
  staleness and explicit partial/unknown states. API requests never poll firewalls.
- OpenAPI 3.0.3 contract (API release 1.2), Postman/Python examples, installation and
  operations documentation, plus authentication/isolation/collector tests.
- Locked security dependency updates for CommonMark and HTTP Foundation.

Read [installation](EXTERNAL-API-INSTALL.md) before enabling the feature.
All published hostnames/addresses in the new examples are reserved examples or
local test fixtures. No deployment credentials, actual customer inventory,
operational hostnames, private backup records or server-specific access details
are part of this contribution.

## Scope and limitations

The API covers registered pfSense/OPNsense firewalls, not general device discovery.
Field/protocol availability depends on each firewall's API permissions and version.
Unsupported values stay null; charts require retaining samples in the consumer.
OPNsense OpenVPN runtime state is explicitly marked unavailable where the adapter
can report only configuration enablement. No firewall configuration writes are added.

The feature tests cover real bearer authentication, company isolation, MSP scope,
non-expiring authorization/revocation, HTTPS, pagination/rate limits, safe
serialization, cached reads and read-only collector behavior. These targeted tests
are distinct from a claim that every existing upstream test passes.
