<p align="center">
  <img alt="PFCentral" src="https://img.shields.io/badge/PFCentral-one%20console%20for%20your%20pfSense%C2%AE%20fleet-0E2238?style=for-the-badge&labelColor=33C5F3">
</p>

<h1 align="center">PFCentral Wiki</h1>

<p align="center">
  Manage, change, upgrade and secure every pfSense firewall you look after, from one console.<br>
  <a href="https://github.com/tr0uble84/pfcentral/releases/latest"><img alt="Latest release" src="https://img.shields.io/github/v/release/tr0uble84/pfcentral?label=release&color=33C5F3"></a>
  <img alt="PHP 8.2+" src="https://img.shields.io/badge/PHP-8.2%2B-0E2238">
  <img alt="Laravel 12" src="https://img.shields.io/badge/Laravel-12-0E2238">
</p>

---

## Start here

| | Page | What you'll find |
| :-: | :-- | :-- |
| 🚀 | **[Installation](Installation)** | One command, a guided wizard, checks at every step |
| 🧱 | **[Adding a firewall](Adding-a-Firewall)** | pfRest, HTTPS and connecting pfSense or OPNsense |
| 📦 | **[Deployments](Deployments)** | One change to many firewalls: pre-checks, waves, undo |
| ⬆️ | **[Upgrades](Upgrades)** | pfSense upgrades in waves, with backups first |
| 🔐 | **[VPN Access](VPN-Access)** | WireGuard remote access with Microsoft 365 sign-in |
| 🛡️ | **[Security](Security)** | Two-factor, firewall web interfaces through PFCentral, locks |
| 🔌 | **[REST API](REST-API)** | Inventory and live status for your own tools |
| 🧰 | **[Troubleshooting](Troubleshooting)** | Fixing a stopped install, error reports, common problems |
| ❓ | **[FAQ](FAQ)** | Short answers |

## How PFCentral fits together

```mermaid
flowchart LR
    U(["👤 You and your team<br/>browser or phone"]) -->|HTTPS| N["Nginx"]
    N --> A["PFCentral<br/>(Laravel app)"]
    N -.->|live updates| R["Reverb<br/>(WebSockets)"]
    A <--> DB[("MySQL / MariaDB")]
    A <--> RD[("Redis<br/>cache, sessions, queue")]
    RD --> W["Background workers<br/>status, deployments, upgrades"]
    S["Scheduler<br/>(every minute)"] --> W
    W -->|REST API over HTTPS| F1["🧱 pfSense<br/>(pfRest)"]
    W -->|REST API over HTTPS| F2["🧱 pfSense"]
    W -->|REST API over HTTPS| F3["🧱 OPNsense"]
    A -->|REST API over HTTPS| F1
    W --> R

    classDef core fill:#0E2238,stroke:#33C5F3,color:#ffffff;
    classDef store fill:#E8F7FD,stroke:#33C5F3,color:#0E2238;
    classDef fw fill:#ffffff,stroke:#0E2238,color:#0E2238;
    class N,A,R,W,S core;
    class DB,RD store;
    class F1,F2,F3 fw;
```

PFCentral reaches each firewall's REST API over HTTPS, so each firewall only needs its API reachable from the
PFCentral server. Every firewall's credentials are stored encrypted, and each company only sees its own firewalls.

## What's in each version

See the **[Versions table in the README](https://github.com/tr0uble84/pfcentral#versions)** and the full
**[changelog](https://github.com/tr0uble84/pfcentral/blob/main/CHANGELOG.md)**. What's planned is on the
**[PFCentral Roadmap](https://github.com/users/tr0uble84/projects/1)**.

## Get help or help out

- Something broke? **[Open an issue](https://github.com/tr0uble84/pfcentral/issues/new/choose)**.
- Want to contribute? Read **[CONTRIBUTING](https://github.com/tr0uble84/pfcentral/blob/main/CONTRIBUTING.md)**, fork, and open a pull request.
- Found a security problem? Report it privately: **[SECURITY](https://github.com/tr0uble84/pfcentral/blob/main/SECURITY.md)**.
