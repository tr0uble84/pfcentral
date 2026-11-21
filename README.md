<h1 align="center">PFCentral</h1>

<p align="center">
  <b>One console for your whole pfSense® fleet.</b><br>
  Manage, change, upgrade and secure every firewall you look after from a single place, built for MSPs and IT teams.
</p>

<p align="center">
  <a href="https://github.com/tr0uble84/pfcentral/releases/latest"><img alt="Latest release" src="https://img.shields.io/github/v/release/tr0uble84/pfcentral?label=release&color=33C5F3"></a>
  <img alt="PHP 8.2+" src="https://img.shields.io/badge/PHP-8.2%2B-0E2238">
  <img alt="Laravel 12" src="https://img.shields.io/badge/Laravel-12-0E2238">
  <img alt="pfSense and OPNsense" src="https://img.shields.io/badge/firewalls-pfSense%20%7C%20OPNsense-0E2238">
</p>

<p align="center">
  <a href="#what-pfcentral-does">Features</a> ·
  <a href="#versions">Versions</a> ·
  <a href="#install">Install</a> ·
  <a href="https://github.com/tr0uble84/pfcentral/wiki">Wiki</a> ·
  <a href="https://github.com/users/tr0uble84/projects/1">Roadmap</a> ·
  <a href="docs/INSTALL.md">Full install guide</a> ·
  <a href="CHANGELOG.md">Changelog</a> ·
  <a href="CONTRIBUTING.md">Contributing</a>
</p>

---

PFCentral is a self-hosted web console for central management of many pfSense firewalls (OPNsense firewalls are
supported too). Instead of signing in to each firewall one by one, you see them all on one dashboard, open any of
them from there, and push the same change to dozens of firewalls at once, checked first and with undo.

It talks to each firewall over its REST API ([pfRest](https://pfrest.org/) on pfSense, the built-in API on
OPNsense), keeps every firewall's credentials encrypted, and separates companies so each customer only sees its own
firewalls.

Developed by **Jay Behi**. See [NOTICE](NOTICE).

## What PFCentral does

### Fleet dashboard
- Every firewall on one screen: online status, CPU, memory, disk, traffic, gateways and alerts, updated live.
- Card and compact views, search and filters, grouped by company.
- Works on a phone: installable as an app (PWA), with a dark mode.

### Deployments: one change, many firewalls
- Send the same change to many pfSense firewalls at once:
  - firewall rules and floating rules
  - aliases and schedules
  - port forwards, outbound NAT and 1:1 NAT
  - DNS Resolver host and domain overrides
  - static routes and system tunables
  - pfBlockerNG IPv4 and IPv6 lists and DNSBL groups
  - DNS servers, time (NTP) servers, remote logging and time zone
- **Checked first:** before anything changes, every firewall is checked for its version, the packages, interfaces
  and aliases the change needs, and whether each item will be added, updated or is already set.
- **In waves, now or scheduled:** test on one firewall first, then roll out to the rest. The next wave only starts
  when the earlier ones went well.
- **Safe on each firewall:** all of a deployment's changes are saved as one configuration change. If a firewall
  can't load them, it puts its previous settings back by itself.
- **Undo** per firewall or for a whole deployment, and **remove** an item (an old rule, alias, port forward) from
  many firewalls.
- **Copy from a firewall:** pick rules, aliases, NAT and settings from one firewall and deploy them to others.
- **Who can deploy:** only the Microsoft 365 people and groups you choose.

### Firewall management
- Rules (per interface and floating) with drag-and-drop ordering, aliases, and NAT (port forwards, 1:1, outbound).
- Interfaces, VLANs, virtual IPs (IP alias, CARP, Proxy ARP), high availability and CARP status.
- VPNs: OpenVPN, IPsec and WireGuard, with client configs and QR codes.
- Services: DHCP and leases, DNS Resolver and Forwarder, NTP, syslog, HAProxy, ACME certificates.
- Security packages: pfBlockerNG (full management, with reports and logs), Suricata alerts, traffic shaper,
  captive portal, Monit.
- System tunables, diagnostics (ping, traceroute, live logs) and encrypted configuration backups with restore.
- **Open the firewall's own web interface through PFCentral**, with no firewall password shared (optional).

### Upgrades
- An upgrade centre for pfSense: checks free disk, saved configurations, the way back (ZFS) and the REST API
  package on every firewall, every night and on demand.
- Upgrades go out in waves, with an encrypted configuration backup taken first and each firewall watched until it's
  back on the new version.

### Remote access VPN
- WireGuard remote access for each company, with Microsoft 365 (Entra ID) sign-in or email-and-password accounts
  with two-factor.
- Access groups per firewall, linked to Microsoft 365 groups; people who leave a group or the company lose access
  automatically.
- A live connections page: who is connected right now, from where, and how much traffic, with disconnect and block.
- A Windows client app with sign-in, diagnostics and support tickets.

### Security and sign-in
- Sign in with Microsoft 365 for your team, or with email and password plus two-factor (TOTP), which can be
  required for everyone.
- Roles: global admin, company admin and read-only, with each company kept separate.
- Firewall web interface sessions use temporary accounts in each technician's name, every change they make is
  logged, and the accounts are removed when the session ends.
- Optional per-firewall lock: the firewall's web interface answers only from the addresses you allow.
- HTTPS to every firewall, with certificate pinning for self-signed certificates.

### REST API
- A token-based REST API (`/api/v1`) for company and firewall inventory and live status, with company isolation and
  rate limits, documented in [OpenAPI](docs/openapi-v1.json).
- Guides: [installation](docs/EXTERNAL-API-INSTALL.md), [inventory and tokens](docs/external-api-v1.md),
  [status fields](docs/PFCENTRAL-STATUS-API.md).

### Updates
- Updates itself from GitHub releases, from the web page. After each update, a "What's new" window lists what
  changed.

## Versions

Each release adds a line here; the full notes for every version are in the [changelog](CHANGELOG.md) and in
PFCentral's own "What's new" window.

| Version | What it added |
| :-- | :-- |
| **0.20** | Guided installer: asks a few questions, checks the server first, installs in 12 checked steps, resumes where it stopped, and reports errors. Project roadmap and wiki. |
| **0.19** | The PFCentral name everywhere in the code, scripts and services. Existing installations keep working unchanged. |
| **0.18** | Deployments cover rules, floating rules, schedules, NAT, DNS overrides, routes, tunables, pfBlockerNG and system settings. Remove items, copy from a firewall, and choose who can deploy. |
| **0.17** | Deployments: one change to many pfSense firewalls, pre-checked, in waves, with automatic and manual undo. |
| **0.16** | Faster: pages open as you click and firewall settings are kept for a few seconds. |
| **0.15** | Security page: require two-factor, open firewall web interfaces through PFCentral, lock firewall web interfaces. |
| **0.14** | Upgrade centre for pfSense firewalls, with checks, backups and waves. |
| **0.13** | VPN live connections: who's connected, live traffic, disconnect and block. |
| **0.12** | New look, Microsoft 365 sign-in for your team (with one-click setup) and the "What's new" window. |
| **0.11** | Full pfBlockerNG management for pfSense firewalls. |
| **0.10** | Two-factor sign-in for email-and-password VPN accounts. |
| **0.9** | Diagnostics and support tickets for the Windows VPN client. |
| **0.8** | VPN: one-click Microsoft 365 setup, email accounts, access groups, the Windows client, Mandrill email. |
| **0.7** | VPN: each company can use its own Microsoft app; work email picks the company. |
| **0.6 and earlier** | Multi-company console for pfSense and OPNsense, WireGuard VPN with Microsoft 365, the REST API. |

## Install

On a fresh Linux server (Ubuntu 24.04 LTS recommended), run:

```bash
curl -fsSL https://raw.githubusercontent.com/tr0uble84/pfcentral/main/install_pfcentral.sh -o install_pfcentral.sh
sudo bash install_pfcentral.sh
```

The installer is a guided wizard:

1. **Asks** for your domain, whether you want a free HTTPS certificate, and an email for it.
2. **Checks the server before changing anything:** system, memory, disk, internet access, the ports PFCentral
   needs, other web servers, and that your domain points to this server. Anything wrong stops it right there.
3. **Installs in 12 steps** (software, database, PFCentral, web server, background workers, HTTPS) and checks each
   step before going on.
4. If a step fails, it shows why, can **send an error report** to this repository (passwords, keys, your domain,
   email and IP addresses removed first), and you **continue where it stopped** with
   `sudo bash ~/.pfcentral-install/install_pfcentral.sh --resume`.

When it finishes, open `https://your-domain/setup` to create your administrator account. Run it with `--check` to
only check a server, or `--help` for every option. Step-by-step details with flowcharts are in the
[wiki](https://github.com/tr0uble84/pfcentral/wiki/Installation).

**Each pfSense firewall needs the [pfRest](https://github.com/pfrest/pfSense-pkg-RESTAPI) package and HTTPS.**
The [full install guide](docs/INSTALL.md) covers manual installation, updating, adding pfSense and OPNsense
firewalls, firewall certificates and troubleshooting.

## Built with

Laravel 12 (PHP 8.2+), Blade, Alpine.js and Tailwind CSS, Laravel Reverb for live updates, MySQL or MariaDB,
Redis, and Laravel Fortify and Sanctum for sign-in and API tokens.

## Contributing

Contributions are welcome. Fork the repository, make your change on a branch, and open a pull request to `main`;
see [CONTRIBUTING.md](CONTRIBUTING.md). Report security issues privately as described in [SECURITY.md](SECURITY.md).

## Disclaimer

PFCentral is an independent project. It is not affiliated with, sponsored by or endorsed by Netgate, Electric Sheep
Fencing LLC or Deciso B.V. pfSense is a registered trademark of Electric Sheep Fencing LLC. OPNsense is a registered
trademark of Deciso B.V.

---

```text
/*
 *      .-------------------.
 *      | o--[tr0uble84]--o |
 *      |      Jay Behi     |
 *       \        |        /
 *        '-------V-------'
 *
 *  > I keep the packets flowing, the bad traffic out,
 *    and the 2 a.m. alerts on mute.
 */
```
