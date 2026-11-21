# VPN Access (WireGuard + Microsoft 365)

Self-service WireGuard remote access for each company managed in PFCentral.

**Who does what:**
* **Global admins (CFBTEL):**
  * set up the Microsoft app once;
  * connect each company's Microsoft 365;
  * turn on VPN for a company firewall. PFCentral then builds WireGuard on it through the API connection it
    already has.
* **Company admins** (PFCentral users with the *admin* role in that company) choose who gets VPN access, picking
  people from their own Microsoft 365.
* **Those people** sign in on the **staff VPN page** (e.g. `https://vpn.example.com/vpn`) with their Microsoft 365
  account. They add a device and get a QR code or `.conf` file for the free WireGuard or AmneziaVPN app. They never
  see the PFCentral console.
* **Losing access:** removing someone's access removes their devices at once. Every 15 minutes (`vpn:sync`) devices of
  disabled or deleted Microsoft 365 accounts are removed too.

Supported firewalls: **pfSense** (REST API package v2) and **OPNsense** (24.x or later, core WireGuard and Firewall →
Automation).

## 1. Microsoft 365 per company

Each company signs in with **its own** Microsoft 365. There are three ways to connect it, chosen per company on its
VPN page:

* **Connect Microsoft 365 automatically (recommended):** click the button (or send the link, valid 7 days, single
  use, to the company's IT admin). A **Global Administrator of the company's Microsoft 365** signs in and accepts
  the permissions. PFCentral then, in the company's tenant, through Microsoft Graph (same approach as CIPP's SAM
  wizard):
  * creates the app **CFBTEL VPN** (this organization only, redirect URI `https://<portal host>/vpn/callback`);
  * adds a client secret (2 years) and saves it encrypted, with the app ID and tenant;
  * creates its enterprise application and grants admin consent: application `User.Read.All` and `GroupMember.Read.All`, and delegated
    `openid profile email User.Read` for the whole organization;
  * reads the tenant name and verified domains, and adds the domains no other company uses as work email domains;
  * deletes CFBTEL's setup app from the company's tenant, so no setup access stays behind.
  Running it again reuses the app, gives it a new secret and removes the old one: use this to renew before the
  secret expires (the company page shows the date, red within 30 days). It needs the setup app in §1b.
* **CFBTEL's shared app (default):** a Global Administrator of the company approves CFBTEL's multi-tenant app once
  (*Approve CFBTEL's app*, or send the link). Only that tenant's accounts can then sign in for the company.
* **The company's own app, by hand (advanced):** create an app registration in **the company's own** Entra and enter its
  Directory (tenant) ID, Application (client) ID and client secret on the company's VPN page. The secret is
  encrypted.
  * Account type: *this organizational directory only*.
  * Redirect URI (Web): `https://<portal host>/vpn/callback`.
  * Graph permissions: delegated `openid`, `profile`, `email`, `User.Read`; application `User.Read.All`, `GroupMember.Read.All` → **Grant
    admin consent**.
  * The company's staff sign-in, people search and access checks then use only that app. Renew the secret on the
    VPN page before it expires.

**Work email domains:** list each company's email domains (e.g. `acme.com`) on its VPN page.
- The staff page asks for the work email first, and its domain picks the company and its app. Microsoft then opens
  with that email filled in.
- Domains are required for companies with their own app, and must be unique across companies.
- Unknown domains use CFBTEL's shared app.

## 1a. CFBTEL's shared Microsoft app (once, in CFBTEL's Microsoft Entra; optional if every company uses its own)

Entra admin center → **App registrations → New registration**:
- Name **CFBTEL VPN**.
- Supported account types: **Accounts in any organizational directory (Multitenant)**.
- Redirect URIs (Web):
  - `https://<portal host>/vpn/callback`
  - `https://<portal host>/vpn/consent/callback`
- **API permissions → Microsoft Graph:**
  - delegated: `openid`, `profile`, `email`, `User.Read`;
  - application: `User.Read.All`, `GroupMember.Read.All`;
  - then **Grant admin consent**.
- **Certificates & secrets → New client secret.** Copy the *Value*.

In PFCentral, open **VPN Access** in the sidebar (global admins). Enter:
- the application (client) ID;
- the secret (stored encrypted with the app key);
- the portal host name;
- the device limit per person.

## 1a-2. Access groups and Microsoft 365 groups (optional)

**Access groups** (company VPN page → **Who has VPN access → Access groups**) decide which firewalls each person
can use. They're offered when the company has **2 or more firewalls with VPN**; with one firewall everyone with VPN
access uses it.
* A group has a name, **one or more firewalls**, and members: **everyone with VPN access**, people picked by hand
  (Microsoft 365 or email-and-password accounts), and/or **linked sign-in provider groups**.
* A person can use the firewalls of **all** their groups.
* **No groups:** every firewall is open to everyone with VPN access. **Once a company has a group, firewalls in no
  group are blocked** (shown in red in the Firewalls table and in the groups box). Deleting the last group opens
  everything again.
* Linked groups are stored as provider + the provider's group id (`vpn_access_group_links`). Microsoft 365 is the
  provider today; another SSO (Google, Okta, any OpenID Connect directory) is added as a new provider without
  changing groups.
* Upgrading to v0.8.5 turns each old "only for one firewall" Microsoft group into an access group for that
  firewall, and puts the company's other firewalls in an "Everyone" group, so nobody loses access.

**Microsoft 365 groups:** pick them in a type-to-search box: results drop down as you type (arrow keys + Enter or a
click adds one, × or Backspace removes one), pick as many as needed (up to 20), then save once.
* **"Microsoft 365 groups that give VPN access"** (whole company): members get VPN access without being added one by
  one; they're added the first time they sign in ("through group"). Nested groups count.
* **Inside an access group** ("…and members of these Microsoft 365 groups"): saved with the group's form. Their
  members are members of the access group (and get VPN access for it even when they aren't in a company group).
* Search: `GET /companies/{id}/vpn/groups/search?q=` (JSON, company admins only). Every new group ID is checked
  with Microsoft before it's saved. Upgrading to v0.8.8 moves the old single company group into the new list.

Changes reach devices within 15 minutes (`vpn:sync`): people who came in through a Microsoft group lose access when
they're in none of the company's groups any more, and anyone loses their devices on firewalls none of their access
groups allow. If Microsoft can't be reached, only devices that no group could allow are removed; the rest waits for
Microsoft. People added by hand keep VPN access.

Needs the application permission `GroupMember.Read.All`. The one-click setup adds it; companies set up before
v0.8.3 run **Run automatic setup again** once. Apps entered by hand need it added with admin consent.

## 1b. CFBTEL's setup app for one-click setup (once, in CFBTEL's Microsoft Entra)

Entra admin center → **App registrations → New registration**:
- Name **CFBTEL VPN Setup**.
- Supported account types: **Accounts in any organizational directory (Multitenant)**.
- Redirect URI (Web): `https://<portal host>/vpn/setup/callback`.
- **API permissions → Microsoft Graph → Delegated:** `Application.ReadWrite.All`, `AppRoleAssignment.ReadWrite.All`,
  `DelegatedPermissionGrant.ReadWrite.All` (keep the default `User.Read`). No application permissions; no admin
  consent is needed in CFBTEL's tenant.
- **Certificates & secrets → New client secret.** Copy the *Value*.

In **VPN Access → One-click Microsoft 365 setup**, enter its application (client) ID and secret. It must be a
different app from the shared sign-in app.

Why this is safe: the setup app has only delegated permissions, so it acts as the admin who signs in and only
while they do; Microsoft makes that admin approve it. The admin's token is used for the setup requests and never
saved. Its enterprise application is deleted from the company's tenant at the end.

## 1c. People without Microsoft 365 (email and password)

For companies without Microsoft Entra, a company admin invites people by email on the company's VPN page
(**…or invite by email**). They get an email with a link (valid 7 days) to set their own password (at least 12
characters), then sign in on the staff page with their email and password.
- Admins can edit the name and email, resend the invitation (or a reset link), and remove the person. Removing
  them removes their devices at once.
- **Forgot password?** on the staff page sends a reset link (valid 1 hour); setting a new password signs out
  other sessions.
- 5 wrong passwords lock the account for 15 minutes.
- **Email settings** (VPN Access, global admins): SMTP server, port, encryption, user, password (encrypted),
  sender, and the invitation template (`{name}`, `{company}`, `{link}`, `{expires}`, `{portal}`; `{link}` is
  required). Leave the SMTP server empty to use PFCentral's own mail setup. **Send a test email** checks it.
- These people aren't checked against Microsoft by `vpn:sync`; removing them is up to the company admin.

## 2. Staff page host name

Only `/vpn` pages and `/vpn-assets` are served on the portal host. The console, its login and the API answer 404
there, and the session cookie is bound to that host.

Point the name at this server, either way:
- with a DNS record and a TLS certificate, or
- through a reverse proxy that keeps the `Host` header.

## 3. Onboarding a company

1. **Companies → company → VPN Access.**
2. **Microsoft 365:**
   - Click **Connect Microsoft 365 automatically** (or send the link to the company's Global Administrator).
     Without the setup app: **Approve CFBTEL's app**, or enter the company's own app by hand.
   - Enter the company's work email domains.
3. **Firewalls → Enable VPN → Create WireGuard.**
   - Choose the LAN users may reach, the WAN, the UDP port, the VPN network and the public name or IP.
   - PFCentral creates:
     - the tunnel;
     - rules on the VPN interface: DNS to the firewall, block the firewall's admin pages, allow the chosen LAN, block
       other private networks, internet (full tunnel);
     - the WAN rule;
     - on OPNsense, an outbound NAT rule.
   - On pfSense, outbound NAT must be Automatic or Hybrid.
   - For a firewall already set up by hand, use **Adopt an existing tunnel** instead.
4. **Who has VPN access → search → Give VPN access**, invite by email, or use a Microsoft 365 group.
   On pfSense, **Create WireGuard** installs the WireGuard package first when it's missing (1–3 minutes).

## 4. Firewall API permissions

* **pfSense:** the API user PFCentral uses needs these privileges:
  - `api-v2-vpn-wireguard-settings-patch`, `-tunnels-get`, `-tunnel-post`, `-peers-get`, `-peer-post`, `-peer-delete`,
    `-apply-post`
  - `api-v2-interfaces-get`, `api-v2-interface-post`, `api-v2-interface-apply-post`
  - `api-v2-firewall-aliases-get`, `-alias-post`, `-rule-post`, `-apply-post`
  - `api-v2-system-packages-get`, `api-v2-system-package-post` (installs the WireGuard package when it's missing)
* **OPNsense:** the API key's user needs these pages:
  - WireGuard
  - Firewall: Automation (filter and source NAT)
  - Firewall: Alias
  - Interfaces: Overview

## How it's built

| Part | Where |
|---|---|
| Tables `vpn_companies`, `vpn_sites`, `vpn_users`, `vpn_devices`, `vpn_audit_logs` | `database/migrations/2026_11_19_000001_create_vpn_tables.php` |
| pfSense / OPNsense drivers | `app/Services/Vpn/PfSenseWireGuardDriver.php`, `OpnSenseWireGuardDriver.php` |
| Devices, removals, sync | `app/Services/Vpn/VpnManager.php`, `php artisan vpn:sync` (scheduled every 15 minutes) |
| Microsoft sign-in, consent, Graph | `app/Services/Vpn/Microsoft.php` |
| One-click Microsoft 365 setup | `app/Services/Vpn/MicrosoftSetup.php`, `app/Http/Controllers/Vpn/MicrosoftSetupController.php` |
| Email-and-password accounts, invitation mail | `app/Services/Vpn/VpnAccounts.php`, `VpnMailer.php` |
| Staff page / console pages | `app/Http/Controllers/Vpn/*`, `resources/views/vpn/*` |
| Portal host restriction | `app/Http/Middleware/VpnPortalHost.php` |
| Tests | `tests/Feature/VpnModuleTest.php` |

**Security notes:**
* **Keys:**
  - Client keys are generated in PFCentral (libsodium).
  - The private key and preshared key go to the person's browser once, as a QR code and a `data:` download. They are
    never stored or logged.
* **Sign-in tokens:**
  - The Microsoft ID token is verified: RS256 signature against Microsoft's keys, audience, lifetime and nonce.
  - The issuer must equal the token's own tenant.
  - The tenant must be linked to a company, and the person must be on that company's list.
* **What the portal touches:**
  - Firewall peers created here are named `cfbvpn-<device id>`.
  - Only such peers are ever removed. The sync also removes `cfbvpn-` peers PFCentral no longer knows about.

## Releasing an update

1. Add the changes to `CHANGELOG.md` under headings that end with the version, e.g. `### Added — v0.8.2`.
2. Set `VERSION` to that version and push to `main`.

The **Publish release** GitHub Action (`.github/workflows/publish-release.yml`) runs the VPN and mail tests, then
creates the tag and the release with those notes. PFCentral's updater (Settings → System Updates) offers it from
there. If the tests fail, nothing is published. An existing release is never changed.


## Windows app: diagnostics and support tickets (v0.9.0)

The CFBTEL VPN app (1.0.6+) has a **Diagnostics** tab. Two endpoints on the staff page host serve it (no CSRF,
throttled, identified by the device's WireGuard public key):

* `POST /vpn/app/status` `{public_key}` → `office, device_active, firewall_reachable, tunnel_found,
  tunnel_enabled, listen_port, peer_present, message`. 404 for unknown keys. The firewall answer is cached 60 s per
  office.
* `POST /vpn/app/support` `{public_keys[], contact_name, contact_email, subject, message, computer, device_id, app,
  problems, warnings, report}` → `{reference, emailed}`. Stored in `vpn_support_reports`, emailed to
  VPN settings → Email → "Support tickets … go to" (default help@cfbtel.com) through the VPN mailer, report attached,
  Reply-To the person. Removed devices may still file tickets; unknown computers get 403 (the app then opens an
  email instead). 5 per device per hour.

Tickets are listed on the company's VPN page and open at `/companies/{id}/vpn/support/{n}`.


## Two-factor sign-in for email-and-password accounts (v0.10.0)

* `vpn_companies.mfa_mode` = `optional` (default) | `required`; `vpn_users.mfa_mode` = null (company) | `optional` |
  `required`. Service: `App\Services\Vpn\VpnMfa` (TOTP via pragmarx/google2fa, QR via bacon-qr-code — both come
  with laravel/fortify).
* Flow: password OK → if two-factor is on: `/vpn/mfa` (code or recovery code) → session. If required but not set up:
  `/vpn/mfa/setup` (QR + key, confirm with a code) → recovery codes page → session. Optional: staff page
  "Turn on two-factor" (`/vpn/mfa/setup`), "Turn off" (`POST /vpn/mfa/disable`, needs a current code).
* `mfa_secret` is encrypted; `mfa_recovery` holds SHA-256 hashes of 8 codes (encrypted); `mfa_last_step` stops a
  code being reused. Wrong codes count with wrong passwords (5 → locked 15 minutes).
* A session ends when two-factor becomes required for someone who hasn't set it up.
* Admin: company setting form, per-person select in Edit and in the invite form, "Reset two-factor".
