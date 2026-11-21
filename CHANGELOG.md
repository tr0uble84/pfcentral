# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Changed — v0.19.0
- The code now uses the PFCentral name everywhere: install and upgrade scripts (`install_pfcentral.sh`,
  `upgrade_pfcentral.sh`), helper scripts (`/usr/local/bin/pfcentral-*`), background services (`pfcentral-worker`,
  `pfcentral-reverb`), the firewall helper folder (`/root/.pfcentral`), settings, logs and documentation.
- New installations go to `/var/www/pfcentral` by default.
- Existing installations keep working with no changes: their install folder, database, background services, SSL
  helper scripts and older system backups are still recognised.
- The firewall helper scripts are uploaded again to each firewall's new folder the next time they are needed.

### Changed — v0.18.1
- The PFCentral name is used throughout the pages, emails, messages, documentation and installer text.
- New README, NOTICE, contribution guide (CONTRIBUTING.md), security policy (SECURITY.md) and pull request and issue
  templates, for the public repository at github.com/tr0uble84/pfcentral.
- The default update channel for new installations is tr0uble84/pfcentral. Existing installations keep the
  repository set in `GITHUB_REPOSITORY`.

### Added — v0.18.0
- **Deployments cover much more:** firewall rules and floating rules, aliases, schedules, port forwards, outbound NAT rules, 1:1 NAT, DNS Resolver host and domain overrides, static routes, pfBlockerNG IPv4 and IPv6 lists and DNSBL groups, system tunables, and the DNS servers, time (NTP) servers, remote logging (syslog) and time zone of each firewall.
- **Remove from many firewalls:** every change can also remove that item (an old rule, alias, port forward and so on). Firewalls that don't have it show "Already set"; undo puts it back where it was.
- **Every internal interface:** a firewall rule can go on every internal (LAN-type) interface of each firewall at once: enter "@internal" as the interface.
- Rules can use a schedule and a gateway (policy routing), and can be added disabled.
- Outbound NAT: if a firewall is in automatic mode it is switched to hybrid (which keeps the automatic rules) so the new rules work; undo switches it back.
- **Copy from a firewall:** read one firewall's rules, aliases, NAT, DNS overrides, routes, pfBlockerNG lists and settings, tick what you want, and deploy it to others. Nothing on that firewall changes.
- **Who can deploy:** global admins pick Microsoft 365 people and groups (from the tenant used for "Sign in with Microsoft 365") under Deployments → Who can deploy. Only they can create, start, schedule or undo deployments; everyone else can only look. Until someone is picked, nobody can deploy.
- The Firewalls page's bulk actions show the deploy options only to people who can deploy.

### Added — v0.17.0
- **Deployments** in the menu: send the same changes to many pfSense firewalls at once, checked first, in waves, with undo. Start one from the Firewalls page (select firewalls → "Deploy changes") or from Deployments → New deployment.
- **Four kinds of change:** firewall rules (at the top or bottom of an interface's list), aliases, port forwards (with their pass rule) and pfBlockerNG IP lists (sources, action, update frequency, logging, custom list).
- **Pre-check on every firewall before anything changes:** pfSense version, whether pfBlockerNG is installed and new enough, whether the interfaces and aliases exist, and for each change whether it will be added, updated or is already set.
- **Packages:** a firewall without a package a change needs (pfBlockerNG) is skipped and listed; install the package and click Retry.
- **Same name, same change:** an alias or pfBlockerNG list with the same name, or a rule or port forward with the same description on the same interface, is updated to match. Nothing is duplicated.
- **Waves, now or scheduled:** the first firewall is wave 1, the test; the rest are wave 2, and you can move firewalls between waves. A wave starts when you click "Deploy now" or at a time you choose. The next wave starts only after the earlier ones finished well.
- **Safe on each firewall:** all of a deployment's changes are saved together in one configuration change (it shows in the firewall's configuration history with the deployment's name). If the firewall can't load the new rules, it puts the previous settings back by itself and the page says so.
- **Undo** per firewall or for the whole deployment: removes what the deployment added and puts back what it changed.
- A rule or port forward that uses a pfBlockerNG list (pfB_...) can be deployed together with the list: it starts empty and pfBlockerNG fills it at its update, which starts by itself after deploying.
- "Add alias" and "Add firewall rule" in the Firewalls page's bulk actions now open a deployment.

### Fixed — v0.16.1
- Firewall web interface through PFCentral: the address the browser asks for is passed to the firewall exactly as sent, so pages like `/index.php?logout` and links with ordered query strings work as on the firewall itself.

### Changed — v0.16.0
- **Faster moving between pages.** In Chrome and Edge, PFCentral starts loading a page while the pointer rests on its link, so most clicks open at once. Links that download, sign in or out, or restart something are never loaded ahead.
- Pages now swap without a white flash and the sidebar stays put. A thin bar at the top shows when a page takes more than a moment.
- **Firewall pages answer faster the second time.** PFCentral keeps each firewall's configuration for 15 seconds, so going back and forth doesn't wait on the firewall again. Live information (status, logs, diagnostics, DHCP leases, traffic) is always read fresh, and any change made through PFCentral, including in the firewall's web interface opened through PFCentral, clears it at once. The kept copy is encrypted. Set `API_CACHE_SECONDS=0` in `.env` to turn it off.
- A page loaded ahead but never opened no longer marks the "What's new" window as seen.

### Added — v0.15.0
- **Security** in the menu (global admins): one page for who can sign in to PFCentral and how your team reaches each firewall. Everything on it is off until you turn it on.
- **Require two-factor:** when on, everyone who signs in with a password or an emailed link must set up two-factor first; until they do, PFCentral only opens their profile page. Signing in with Microsoft 365 counts as two-factor. The page lists who hasn't set it up yet.
- **Open firewalls through PFCentral (optional):** adds "Open web interface" to each pfSense firewall's pages. PFCentral creates a temporary firewall account in the technician's name, signs in for them and keeps the firewall's sign-in to itself, so no firewall password is shared. Every change they save is logged, and the account is removed when the session ends, after 30 idle minutes (you choose) or after 8 hours. Read-only users can't open it. It needs its own host name, like the VPN page.
- **Lock firewall web interfaces to PFCentral (optional, per firewall):** a locked firewall's web interface answers only the addresses you allow from the internet; LAN access is never changed. Before locking, PFCentral checks that its own connection comes from an allowed address, and if it can't confirm the lock afterwards, the firewall undoes it by itself within 3 minutes. Check, lock and unlock from the Security page.
- The Security page lists every web interface session: who, which firewall, how long and what they changed, with "End now" for open ones.

### Added — v0.14.0
- **Upgrades** in the menu: an upgrade centre for pfSense firewalls, ready for pfSense 26.10.
- **Check:** PFCentral reads each firewall's version, whether a newer version is offered, free disk, saved config versions, the way back (ZFS) and the REST API package. It checks every night and on demand, and shows what to fix before upgrading.
- **Plan in waves:** select firewalls and plan an upgrade. The first one is wave 1, a test; the rest go in wave 2, and you can move firewalls between waves. Each wave is started by hand, and only after the earlier waves finished well.
- **For each firewall, PFCentral:** checks again, saves an encrypted copy of its configuration (downloadable from the page), starts the upgrade, and watches until the firewall is back on the new version. A firewall that doesn't come back within an hour is flagged.
- **REST API package:** a pfSense upgrade removes it. With SSH set up for the firewall, PFCentral reinstalls it by itself; otherwise the page shows the one command to run, then "Check again".
- "System Update" in the firewall list's bulk actions now plans an upgrade here, instead of starting upgrades with no checks.

### Added — v0.13.0
- **VPN Access → Live connections**, a new page for CFBTEL admins. It shows who is on the VPN right now: the person, their company, which firewall and device, where they connect from, and their live traffic. The page updates every few seconds without reloading.
- A traffic graph shows the last five minutes of VPN traffic for everyone together, and each firewall shows how many people are on it and whether it's answering.
- Search by person, email, company, firewall, device or IP, filter by company or firewall, and switch between Connected, All devices and Not connected. Press / to jump to the search box.
- Click a person to see their device, live traffic, other devices, sign-in method and recent VPN activity.
- **Disconnect this device**: removes the device from the firewall at once. To reconnect, the person signs in again.
- **Block**: disconnects all of a person's devices and stops them signing in until someone unblocks them. Blocked people are listed at the bottom of the page and marked on their company's VPN page.

### Fixed — v0.12.6
- The "What's new" window opens only once per update: it counts as seen as soon as it appears, so it no longer
  comes back on every page when you move on without clicking "Got it".

### Fixed — v0.12.5
- **What's new** now works: the release notes file wasn't included in the update packages, so the window never
  appeared and the "What's new" link did nothing. After an update it opens once by itself with the notes of every
  version since you last closed it; the link at the bottom of the page opens it again any time.

### Fixed — v0.12.4
- Right after the automatic Microsoft 365 setup, searching groups said the app "can't read groups yet" for up to an
  hour. Microsoft can take a few minutes to switch on a new app's permissions, and PFCentral kept the first
  (permission-less) access token for an hour. Such a token is now kept for 30 seconds only, and a refused request
  is retried once with a fresh token, so groups show up as soon as Microsoft is ready.

### Added — v0.12.3
- **Sign-in → "Set up automatically with Microsoft 365"**: one click, sign in as a Global Administrator of your
  Microsoft 365, and PFCentral creates the "PFCentral sign-in" app in your tenant with the sign-in address,
  permissions (with admin consent) and secret already filled in, then saves the tenant and app on the Sign-in page.
  Nothing to copy. Running it again renews the secret. Then pick the groups and turn it on. Setting it up by hand
  is still there under "Set up by hand (advanced)". It uses the same CFBTEL setup app as the VPN's one-click setup.

### Fixed — v0.12.2
- Updates rebuild the look of the app again. On this server the rebuild step stopped with "vite: not found", so new
  colours and layouts never appeared after an update. If that happens now, the updater runs Vite directly, and if
  that fails too it reinstalls the build tools and tries again.

### Fixed — v0.12.1
- **Email sign-in links work again.** Opening the link now shows a "Continue as …" page and signs you in when you
  click Continue. Before, mail scanners such as Microsoft Defender opened the one-time link first and used it up,
  and links could fail behind the server's proxy or port ("401 Unauthorized"). An expired or used link now says so
  on the sign-in page.
- **Sign-in page layout.** It no longer stretches across the whole screen when the server hasn't rebuilt its styles:
  the sign-in pages now carry their own stylesheet. Clearer buttons, a styled "Keep me signed in" box.

### Changed — v0.12.1
- The **CFBTEL logo** is used on the sign-in page, in the menu and in the browser tab, and the CFBTEL cloud is
  the 3D logo that spins on the sign-in page.

### Added — v0.12.0
- **New look: PFCentral**, with a new logo (a shield with a central hub), CFBTEL
  navy and cyan colours, the Inter typeface, and "© CFBTEL. All rights reserved." at the bottom of every page.
  If you had kept the old default grey sidebar, it is now navy; colours you picked yourself are kept.
- **New sign-in page** with a live 3D scene: the PFCentral shield spins inside a firewall ring while traffic flows in;
  threats are stopped at the ring. Show/hide password, and an expired page now just asks you to sign in again
  instead of showing "419 Page Expired".
- **Sign in with Microsoft 365** for your own team (the MSP). Set it up under **Sign-in** in the menu (or the gear
  on the login page): your tenant ID and the Microsoft 365 groups that may sign in. Everyone in those groups gets
  the Administrator role for all companies; membership is checked at every sign-in. Email and password keep working.
- **What's new**: this window, shown once after each update. Open it again any time from the version number at the
  bottom of the page or in the menu.

### Added — v0.11.0
- **Firewall → pfBlockerNG** (pfSense firewalls). Manage the pfBlockerNG package from PFCentral with the same
  sections as pfSense: Overview, General, IP, DNSBL, Feeds, Update, Reports, Logs and Quick setup.
  - Not installed yet: one click installs `pfSense-pkg-pfBlockerNG-devel`.
  - Quick setup does what the pfSense wizard does (PRI1 IP list, ADs Basic DNSBL list, default whitelist) and adds
    the DNSBL virtual IP on Localhost (10.10.10.1 by default) when it is missing.
  - General, IP and DNSBL settings, with "Apply now" (reload). MaxMind key and IPinfo token are write-only: they are
    never sent back to the browser.
  - Feeds: list, add, edit and delete IPv4, IPv6 and DNSBL groups (sources, custom entries, action, frequency,
    logging), or add ready-made groups from the pfBlockerNG feed catalog.
  - Update: Force update / cron / reload with the live pfblockerng.log.
  - Reports: most blocked addresses, domains, devices and feeds, recent IP and DNSBL events with a filter, and a
    Whitelist button for blocked domains. Logs: every pfBlockerNG log file.
  - Read-only users can see everything but change nothing. OPNsense firewalls show "not supported".
  - How it works: pfBlockerNG has no API, so PFCentral copies a small helper script to `/root/.pfcentral/` on
    the firewall through the REST API command prompt (in pieces, because commands are limited to 1024 characters)
    and runs it with pfSense's PHP. It edits the same config.xml sections as the pfBlockerNG pages, so everything
    stays visible in pfSense.

### Changed — v0.10.1
- When PFCentral's mail is set to Mandrill (System Settings → Email Configuration), every VPN email
  (invitations, password links, support tickets from the Windows app) goes through Mandrill from that setting's
  From address, ignoring the VPN SMTP settings. VPN settings → Email says so.
- Support tickets from the app get a random 5-digit reference (VPN-48213) in the email subject.

### Added — v0.10.0
- Two-factor sign-in for email-and-password VPN accounts, with an authenticator app (Google Authenticator,
  Microsoft Authenticator, …) set up by scanning a QR code:
  - Company setting (VPN page → Who has VPN access): **Optional** (default) or **Required**; each person can be
    set differently (invite form and Edit: company setting / required / optional).
  - Required: after the password, the person must set it up (QR code, then a 6-digit code) before getting in;
    existing sessions end when it becomes required. Optional: "Turn on two-factor" on the VPN page (and off,
    with a current code).
  - Every sign-in then asks for the code. Each code works once; 8 one-time recovery codes are shown at setup;
    5 wrong codes lock the account for 15 minutes. Secrets are stored encrypted.
  - Admins can **Reset two-factor** (lost phone). The invitation email says when it's required.
  - Works with the CFBTEL VPN Windows app's sign-in. Microsoft 365 accounts keep using Microsoft's MFA.

### Added — v0.9.0
- For the CFBTEL VPN Windows app's new Diagnostics tab:
  - `POST /vpn/app/status`: the office firewall as PFCentral sees it for that device (firewall reachable,
    tunnel and listening port, this device's peer present, or "device removed"). The app identifies itself with the
    device's WireGuard public key; one firewall look per office per minute.
  - `POST /vpn/app/support`: support tickets with the person's message, the checks, device ID and the VPN log.
    Stored as VPN-<number>, emailed to the support address (VPN settings → Email, default help@cfbtel.com) with
    the report attached and Reply-To set to the person. At most 5 per computer per hour.
  - Company VPN page: "Support tickets from the app" list, each with the message and the full report (download .txt).

### Fixed — v0.8.9
- "Turn off VPN for this firewall" said "Remove this site's devices first" even with no devices left: devices
  already removed but still waiting for the firewall to confirm ("being removed") counted. Now only devices that
  can still connect block it (and are named); the waiting ones are taken off the firewall and cleared. If the
  firewall doesn't answer, it's turned off anyway and the leftover peer names (cfbvpn-…) are listed to delete by hand.

### Changed — v0.8.8
- Microsoft 365 groups are picked with a type-to-search dropdown instead of "Search groups" reloading the page:
  results appear as you type, you can pick several (shown as chips with × to remove), then save once.
- The whole company can now use several Microsoft 365 groups (was one). The old group carries over.
- Access groups take their Microsoft 365 groups in the same form as their firewalls and people.

### Fixed — v0.8.7
- One-click Microsoft 365 setup stopped with "Microsoft refused to read the organization … Insufficient privileges":
  the setup sign-in now also asks for `User.Read` (needed to read the organization's name and domains), and if that
  still can't be read the setup carries on without it.

### Fixed — v0.8.6
- Updating to v0.8.5 failed on MySQL ("Identifier name … is too long"): the access group tables now use short index
  names. The migration removes the empty tables the failed update left behind and runs again; nothing else changed.
- The release tests now run on MySQL instead of SQLite.

### Added — v0.8.5
- **VPN access groups.** A company with 2 or more VPN firewalls can make groups: a name, one or more firewalls, and
  members (everyone with VPN access, people picked by hand, and/or linked Microsoft 365 groups). People use the
  firewalls of all their groups. Once a company has a group, firewalls in no group are blocked; with no groups
  everything stays open as before. Linked groups are stored per sign-in provider so other SSO can be added later.
  The sync removes devices on firewalls a person's groups no longer allow. The old "Microsoft 365 group for one
  firewall" setting is replaced: existing ones become access groups (other firewalls go into an "Everyone" group).
- Staff page: people with VPN access but no firewall get a clear message; with one location its name is shown.

### Changed — v0.8.5
- Staff page support address is help@cfbtel.com.

### Changed — v0.8.4
- The staff page's Windows steps now install **CFBTEL VPN for Windows** instead of WireGuard. The link
  (`/vpn/download/windows`, `?arch=arm64` for ARM PCs) goes straight to the newest installer on the
  cfbtel/cfbtel-vpn-windows GitHub releases (checked hourly; falls back to the releases page). Set
  `VPN_WINDOWS_REPO` in `.env` to use another repository. The AmneziaVPN tip is no longer shown for Windows.

### Fixed — v0.8.4
- Create WireGuard on pfSense: the WireGuard package check now reads the firewall's installed-package list
  (a WireGuard endpoint could answer before the package was really there, so the install was skipped and setup
  stopped with "requires the pfSense-pkg-WireGuard package"). If pfSense still reports the package missing, it is
  installed and the step is retried once; after installing, PFCentral waits up to a minute for it to start.
- Create WireGuard on pfSense stopped with "Field `if` must be one of [...]": pfSense's REST API can't assign a
  WireGuard tunnel as an interface. Setup no longer assigns it: the tunnel gets its address itself, the VPN rules go
  on pfSense's **WireGuard** interface group (Firewall → Rules → WireGuard), outbound NAT gets a rule for the VPN
  network (Automatic mode is switched to Hybrid, which keeps every automatic rule), and the DNS Resolver gets an
  access list entry for the VPN network. A tunnel assigned by hand keeps its interface. Clicking Create again after
  a stop finishes the "CFBTEL VPN" tunnel the earlier attempt left (same port, no devices) and skips what's there.

### Added — v0.8.3
- Sign-in for the CFBTEL VPN Windows app: the app opens the staff page (`/vpn/app`), the person signs in and picks
  the office, the device is created from the app's own public key (the private key never leaves the computer), and
  the app redeems a one-time code (`POST /vpn/app/token`, 5 minutes, once) for its tunnel settings.
- Microsoft 365 groups for VPN access: a company group (e.g. "VPN") gives its members access without adding them
  one by one, and a group per firewall limits that firewall to its members. Leaving a group removes the access at
  the next sync (15 minutes). Needs GroupMember.Read.All, which the one-click setup now grants (run it again for
  companies already set up).
- pfSense: "Create WireGuard" installs the WireGuard package (pfSense-pkg-WireGuard) first when it's missing.

### Fixed — v0.8.3
- VPN "Create WireGuard" checks the firewall's existing tunnels first: a UDP port already in use now stops with a
  clear message (adopt that tunnel or pick another port) before anything on the firewall is changed.
- System Performance tuning finds the PHP-FPM pool of the PHP version actually installed (e.g. PHP 8.4 / 8.5 at
  `/etc/php/8.5/fpm/pool.d/www.conf`) and restarts that version's PHP-FPM service; it only knew PHP 8.1–8.3.

### Fixed — v0.8.2
- Saving System Settings no longer rewrites APP_URL without its port (e.g. `https://host:8443`), which sent the
  browser to the wrong port after saving and broke the page styles until `.env` was fixed. When the host name and
  scheme are unchanged, `.env` and Nginx are left alone; SSL changes on the same host keep the custom port.

### Added — v0.8.1
- Mandrill (Mailchimp Transactional, mandrillapp.com) as a mail driver in System Settings → Email Configuration,
  sent through Mandrill's HTTPS API (messages/send-raw). The API key is stored encrypted and never shown again.
  VPN invitations use it too when the VPN's own SMTP server is left empty.

### Changed — v0.8.1
- The Mailgun API key is no longer written back into the settings page; a blank field keeps the saved key.
- A failed test email now shows the reason.

### Added — v0.8.0
- One-click Microsoft 365 setup: **Connect Microsoft 365 automatically** on a company's VPN page (or a 7-day,
  single-use link for its IT admin). A Global Administrator signs in once; PFCentral creates the company's own
  "CFBTEL VPN" app in its tenant with admin consent (User.Read.All; openid profile email User.Read), a 2-year
  secret, its tenant name and email domains, then removes CFBTEL's setup access. Running it again renews the secret.
  Needs a "CFBTEL VPN Setup" app (delegated permissions only) in VPN settings. The secret's expiry shows on the page.
- Email-and-password VPN accounts for companies without Microsoft Entra: email invitations with a set-password link,
  forgot-password links, lockout after 5 wrong passwords, edit / resend / remove by company admins.
- Email settings for the VPN (SMTP, sender, invitation template, test email).

### Added — v0.7.0
- Per-company Microsoft apps: a company can use its own Entra app registration (tenant ID, app ID, encrypted
  secret) instead of approving CFBTEL's shared app; its sign-in, people search and access checks use that app only.
- Work email first on the staff VPN page: the email domain picks the company and its Microsoft app (login hint).
  Email domains are set per company and must be unique.

### Fixed — v0.6.1
- Staff VPN page behind a reverse proxy (e.g. Coolify): sign-in limits use the visitor's address forwarded by the
  configured proxy IPs (VPN settings), only for that proxy on the portal host name.
- After an in-app update the first web request clears PHP-FPM's opcode cache once, so workers can't keep running
  the previous release (stale routes caused "Route not defined" errors until PHP-FPM was restarted).

### Added — VPN Access (WireGuard + Microsoft 365)
- Per-company self-service WireGuard remote access for pfSense and OPNsense: CFBTEL admins connect a company's
  Microsoft 365 (admin consent) and build WireGuard on its firewall (tunnel, rules, WAN rule, OPNsense outbound NAT)
  or adopt an existing tunnel.
- Company admins give and remove VPN access by picking people from their own Microsoft 365.
- Staff VPN page on its own host name (e.g. vpn.example.com) with Microsoft 365 sign-in, QR code / `.conf`
  setup for WireGuard or AmneziaVPN, and device removal; the console isn't served on that host.
- `vpn:sync` every 15 minutes removes devices of people who lost access or whose account was disabled or deleted.
- See docs/VPN-MODULE.md.

### Added — External API (Jay Behi)
- Company-scoped and explicitly authorized all-company MSP inventory REST API
  with Sanctum authentication, HTTPS enforcement, rate limits and safe field lists.
- Private CLI token issuance/revocation, including explicitly authorized
  non-expiring global-administrator tokens.
- Per-firewall and bulk cached telemetry endpoints with isolated background
  collection, freshness metadata, interface rates and supported VPN status.
- OpenAPI 1.2 contract, installation/operations documentation, Postman/Python
  examples and API authentication/isolation/collector regression tests.

### Security
- Pin patched league/commonmark 2.10.4 and symfony/http-foundation 7.4.21.

## [0.4.4] - 2026-02-13

### Changed
- **Release Pipeline**: Temporarily disabled Minisign verification in release workflow to restore automated release publishing.

## [0.4.3] - 2026-02-13

### Added
- **System Update Manager**: Migrated system update functionality to the System Settings card with "Check" and "Install" capabilities.
- **Update Checks**: Integrated GitHub Releases API for version checking.

## [0.4.2] - 2026-02-13

### Changed
- Internal release for testing deployment pipelines.

## [0.4.1] - 2026-02-04

### Fixed
- **Apply Changes Persistence**: Fixed issue where the "Apply Changes" banner would persist after application by standardizing dirty state detection in `/var/run`.
- **Apply Logic Refactor**: Refactored `PfSenseApiService` to handle dirty states generically for Tunables, Rules, NAT, Aliases, and Interfaces.

## [0.4.0] - 2026-01-22

### Added
- **Flicker-Free Dashboard**: Implemented global `x-cloak` support and optimistic initialization logic to ensure a smooth, stable page load experience.

### Fixed
- **Overlay Flashing**: Resolved a race condition where offline overlays would briefly appear during Alpine.js initialization.
- **Connection State Logic**: Refined the initial `online` state evaluation within the `firewallCard` component to prevent false-offline indicators during background data syncing.

## [0.3.1] - 2026-01-22

### Fixed
- **Swap Usage Detection**: Enhanced `PfSenseApiService` to support deep-nested metrics and multiple naming variations for Swap, Memory, and CPU.
- **Metric Formatting**: Added automatic parsing for percentage strings (e.g., "5.2%") into numeric values for UI bars.
- **Uptime/System Fallbacks**: Added multiple fallback keys for Uptime and Temperature to support different pfSense API implementations.

## [0.3.0] - 2026-01-22

### Added
- **Centralized Dashboard Coordinator**: Implemented `dashboardCoordinator` to manage simultaneous firewall updates, eliminating the "waterfall" effect.
- **Failover Batch Sync**: Added automatic single-request batch fetching for when WebSockets are disconnected.
- **Data Resilience**: Cached metrics are now preserved for offline devices, ensuring dashboard cards stay populated with last-known data behind the blur overlay.

### Changed
- **Performance Optimization**: Removed per-card `fetchStatus()` on initialization, reducing page load overhead significantly.
- **API Schema Flattening**: Flattened the `PfSenseApiService` response structure to simplify metric binding on the frontend.
- **Unified Update Pipeline**: Standardized the data format shared between WebSockets, Ajax, and background jobs.

### Fixed
- **Metric Mapping Issues**: Fixed "Unknown" values for CPU, Memory, and Version by unifying nested and flat data paths in Alpine components.
- **Dashboard Data Freezing**: Resolved issues where real-time updates were ignored due to property structure mismatches.
- **Offline UI Breakage**: Fixed cards disappearing or being cleared when a firewall becomes unreachable.

## [0.2.0] - 2026-01-22

### Added
- **Firewall Counter Display**: Added "Showing X of Y firewalls" counter with real-time filtering support on dashboard and firewalls index pages
- **Shared Filterable Mixin**: Created reusable `window.filterableMixin()` component for consistent filtering across pages
- **URL-Persisted Filters**: Search, status, and customer filters now persist in URL query parameters and survive page refreshes
- **Settings Page Restore**: Added "Restore to Default" buttons for logo and favicon in system settings
- **Gateway Display Fallback**: Offline firewalls now show WAN gateway with "Unknown" status instead of empty section
- **Documentation**: Added usage guide for filterable mixin at `.agent/filterable-mixin-usage.md`

### Changed
- **Gateway UI Redesign**: Updated gateway display with neutral backgrounds and color-coded left borders
- **Table Spacing**: Increased vertical padding in System Information tables for better readability
- **Gateway Labels**: Standardized gateway labels to use `text-sm` font size across all pages
- **Project Organization**: Moved deployment scripts (`install.sh`, `setup_reverb.sh`) to `/scripts` directory
- **Route Names**: Updated system settings routes from `system.customization.*` to `system.settings.*`

### Fixed
- **Settings Page Error**: Fixed "Route [system.customization.index] not defined" error when saving settings
- **Layout Stability**: Resolved issue where gateway section disappeared entirely for offline firewalls
- **Code Duplication**: Reduced ~100+ lines of duplicate filtering logic by using shared mixin

### Technical
- Refactored dashboard and firewalls index pages to use shared `filterableMixin`
- Added `device-updated` event with detailed payload (id, online status)
- Implemented reactive filtering with Alpine.js computed properties
- Enhanced event-driven architecture for real-time status updates

## [0.1.0] - Initial Release

### Added
- Initial PFCentral application for pfSense firewall management
- Dashboard with firewall status monitoring
- Real-time status updates via WebSocket (Reverb)
- Queue-based background job processing
- Multi-tenant architecture with company isolation
- System settings management
- Custom logo and favicon support
- Dark/light theme support

