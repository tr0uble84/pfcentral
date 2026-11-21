# Installing PFCentral

This guide covers installing PFCentral, keeping it updated, adding firewalls and the HTTPS certificate each firewall needs. Back to the [README](../README.md).

PFCentral can be installed automatically using the included installation script or manually for administrators who prefer to configure each component themselves.

### Supported Operating Systems

The automated installer is currently tested on:

- Ubuntu 24.04 LTS
- Fedora 42+
- Arch Linux (experimental)

Other Linux distributions may work but are not officially tested.

---

## Automatic Installation (Recommended)

```bash
curl -fsSL https://raw.githubusercontent.com/tr0uble84/pfcentral/main/install_pfcentral.sh -o install_pfcentral.sh
sudo bash install_pfcentral.sh
```

The installer asks for your domain, whether to get a free Let's Encrypt certificate, and an email for it. It then
checks the server **before changing anything** (system, memory, disk, internet access, ports 80, 443, 3306, 6379
and 8080, other web servers, the install folder, and that your domain points to this server), and installs in
12 steps:

1. Software: Nginx, PHP, MySQL, Redis, Supervisor, Certbot, Composer, Node.js
2. Database and its user (a strong password is generated)
3. Download PFCentral
4. Settings (`.env`)
5. PHP libraries (Composer)
6. App key and database tables
7. Build the web pages
8. Permissions and the safe SSL helper scripts
9. Web server (Nginx) and firewall ports (ufw or firewalld, when active)
10. Background workers, live updates (Reverb) and the scheduler
11. HTTPS certificate (Let's Encrypt), when chosen
12. Final check

Each step is checked before the next one starts, and saved as a checkpoint. If a step fails, the installer stops,
shows the reason and the last lines of the log (`/var/log/pfcentral-install.log`), can send an error report to
GitHub, and you continue with:

```bash
sudo bash ~/.pfcentral-install/install_pfcentral.sh --resume
```

| Option | What it does |
| :-- | :-- |
| `--check` | Only the questions and pre-checks; nothing is installed |
| `--resume` | Continue an installation that stopped |
| `--verbose` | Show every command's output while installing |
| `--unattended` | No questions: set `PFC_DOMAIN`, `PFC_EMAIL`, `PFC_HTTPS=yes\|no`, `PFC_REPORT=yes\|no` (and optionally `INSTALL_DIR`, `DB_USER`, `DB_PASS`) |

When it finishes, browse to `https://your-domain/setup` (or `http://your-server-ip/setup`) to create your
administrator account.

---

## Manual Installation

For administrators who prefer to install and configure all components manually.

### 1. Install Required Software

Install the following packages using your distribution's package manager:

- PHP 8.3 or newer
- Composer
- Node.js 20 or newer
- Nginx
- MySQL or MariaDB
- Redis
- Supervisor

Required PHP extensions:

- bcmath
- curl
- gd
- intl
- mbstring
- mysqli/mysql
- redis
- xml
- zip

---

### 2. Clone the Repository

```bash
git clone https://github.com/tr0uble84/pfcentral.git pfcentral
cd pfcentral
```

---

### 3. Install PHP Dependencies

```bash
composer install --no-dev --optimize-autoloader
```

---

### 4. Create Environment File

```bash
cp .env.example .env
```

Edit `.env` and configure the application:

```env
APP_NAME="PFCentral"
APP_ENV=production
APP_DEBUG=false
APP_URL=http://your-server-ip

DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=pfcentral
DB_USERNAME=pfcentral
DB_PASSWORD=your_password

CACHE_STORE=redis
SESSION_DRIVER=redis
QUEUE_CONNECTION=redis

REDIS_CLIENT=phpredis
REDIS_HOST=127.0.0.1
REDIS_PORT=6379
```

---

### 5. Generate Application Key

```bash
php artisan key:generate
```

---

### 6. Run the Installation Wizard

```bash
php artisan install
```

The installation wizard will:

- Configure application settings
- Verify database connectivity
- Create required database tables
- Seed default data
- Generate required application secrets

---

### 7. Install Frontend Dependencies

```bash
npm install
npm run build
```

---

### 8. Configure Storage

```bash
php artisan storage:link
```

---

### 9. Configure Queue Workers

Create a Supervisor configuration similar to:

```ini
[program:pfcentral-worker]
process_name=%(program_name)s_%(process_num)02d
command=php /var/www/pfcentral/artisan queue:work redis --sleep=3 --tries=3
autostart=true
autorestart=true
user=www-data
numprocs=8
redirect_stderr=true
stdout_logfile=/var/www/pfcentral/storage/logs/worker.log
```

Reload Supervisor:

```bash
supervisorctl reread
supervisorctl update
```

---

### 10. Configure Laravel Reverb

Create a Supervisor configuration similar to:

```ini
[program:pfcentral-reverb]
command=php /var/www/pfcentral/artisan reverb:start --host=0.0.0.0 --port=8080
directory=/var/www/pfcentral
autostart=true
autorestart=true
user=www-data
redirect_stderr=true
stdout_logfile=/var/www/pfcentral/storage/logs/reverb.log
```

Reload Supervisor:

```bash
supervisorctl reread
supervisorctl update
```

---

### 11. Configure the Scheduler

Add the following cron entry:

```cron
* * * * * php /var/www/pfcentral/artisan schedule:run >/dev/null 2>&1
```

---

### 12. Configure Permissions

```bash
chown -R www-data:www-data storage bootstrap/cache
chmod -R 775 storage bootstrap/cache
```

Adjust the web server user as required for your distribution.

---

### 13. Install SSL Privilege Wrappers

PFCentral's built-in Let's Encrypt SSL manager requires the ability to run `certbot` and reload Nginx from the web server process. Rather than granting broad `sudo` access to those tools (which would allow arbitrary code execution via certbot's `--deploy-hook` flag), PFCentral installs narrow wrapper scripts that accept only the exact arguments needed and validate all inputs.

```bash
sudo bash /var/www/pfcentral/scripts/setup-ssl-permissions.sh
```

This installs five wrapper scripts into `/usr/local/bin/` and creates a sudoers entry granting `www-data` access **only** to those wrappers — not to `certbot` or `nginx` directly. This step is required before the **Install Certificate** button in Settings → Site Configuration will work.

> [!NOTE]
> This step is only required if you use PFCentral's built-in SSL management. If you manage SSL certificates externally, you can skip it.

---

### 14. Configure Nginx

Set the web root to:

```text
/var/www/pfcentral/public
```

Example configuration:

```nginx
server {
    listen 80;
    server_name _;

    root /var/www/pfcentral/public;
    index index.php;

    location / {
        try_files $uri $uri/ /index.php?$query_string;
    }

    location ~ \.php$ {
        include fastcgi_params;
        fastcgi_pass unix:/run/php-fpm/www.sock;
        fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
    }
}
```

Restart Nginx and PHP-FPM after configuration.

---

## Host environment preparation

For an existing deployment, run `sudo bash upgrade_pfcentral.sh --check` to assess PHP, Redis/Valkey, workers, Reverb and the scheduler. Run without `--check` for the guided host-preparation wizard. Use `--install-dir` if the deployment is outside `/var/www/pfcentral`.

The wizard supports Ubuntu, Fedora and Arch families with systemd, explains each host change, and backs up edited configuration. It preserves the built-in GitHub application updater. See the [host-readiness guide](HOST_READINESS.md) for distribution details, maintenance precautions and recovery.

## Updating PFCentral

To update an existing installation:

```bash
cd /var/www/pfcentral

sudo git pull

sudo bash upgrade_pfcentral.sh

composer install --no-dev --optimize-autoloader

php artisan migrate --force

npm install
npm run build

php artisan optimize:clear

supervisorctl restart all
systemctl reload nginx
```

> [!IMPORTANT]
> `upgrade_pfcentral.sh` now automatically installs the SSL privilege wrappers (requires root). If you run the update steps without the script, run this once manually:
> ```bash
> sudo bash /var/www/pfcentral/scripts/setup-ssl-permissions.sh
> ```
> This is a one-time step for deployments upgrading from an earlier version. It is safe to run on every update.

## Adding Your First Firewall

PFCentral supports both pfSense and OPNsense firewalls with platform-native workflows.

### Adding a pfSense Firewall

To manage a pfSense firewall, ensure the [pfSense REST API (pfRest)](https://github.com/pfrest/pfSense-pkg-RESTAPI) package is installed on the target pfSense machine.

1. Log in to PFCentral as an administrator.
2. Navigate to **Firewalls > Add Firewall**.
3. Select **pfSense** as the firewall type.
4. Enter the **Firewall Name**, **URL** (e.g. `https://192.0.2.1`), and **API Credentials** (Username/Password or API Token).
5. Click **Connect**. PFCentral will verify connectivity, fetch system info and interfaces, and register the firewall.

### Adding an OPNsense Firewall

OPNsense includes a built-in REST API out of the box — no third-party packages are required. You can add an OPNsense firewall using manual API keys or PFCentral's automatic provisioning.

#### Method A — Manual API Key & Secret (Recommended)

1. **Generate the API Key in OPNsense**:
   - In your OPNsense Web GUI, navigate to **System > Access > Users** (`/ui/auth/user`).
   - In the **Users** table, locate your administrative user (e.g. `root`).
   - Under the **Commands** column on the right side of the row, click the **Key icon** (API keys).
   - Your browser will immediately download a text file (`apikey.txt` or `key_<username>.txt`) containing:
     ```ini
     key=AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA
     secret=BBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBB
     ```
   - *(Alternatively, you can click the **ApiKeys** tab at the top of the Users page to view and manage existing keys).*

2. **Add to PFCentral**:
   - In PFCentral, navigate to **Firewalls > Add Firewall**.
   - Select **OPNsense** as the firewall type.
   - Enter the **Firewall Name** and **URL** (e.g. `https://192.0.2.11`).
   - Paste the 80-character `key` string from the downloaded file into **OPNsense API Key** and the `secret` string into **OPNsense API Secret**.
   > **Important:** OPNsense's REST API (`/api/*`) strictly authenticates using the generated API Key and Secret pair. Do **not** enter your login username (`root`) or Web GUI password in the API Key/Secret fields.
   - If your OPNsense instance uses a self-signed or internal certificate, expand **Trust a native or self-signed HTTPS certificate** and upload your exported `.crt`/`.pem` certificate file.
3. Click **Connect** (or **Save Changes**). PFCentral will verify connectivity, fetch system telemetry and interfaces, and register the firewall.

#### Method B — Automatic API Key Provisioning

If you prefer not to create the API key manually:
1. In PFCentral, go to **Firewalls > Add Firewall** and select **OPNsense**.
2. Enter the **Firewall Name** and **URL**.
3. Check the box **"Auto-generate API Keys using Web GUI credentials"**.
4. Enter your OPNsense Web GUI **Username** (e.g. `root`) and **Password**.
5. If using a self-signed certificate, upload the `.crt`/`.pem` certificate file.
6. Click **Connect**. PFCentral will securely authenticate once against your OPNsense Web GUI session, automatically provision a dedicated API key and secret, and save them.

---

## Firewall HTTPS Certificate Requirements

PFCentral requires **HTTPS** for every firewall API connection. The API credentials are never sent over plain HTTP. How you satisfy this requirement depends on the certificate in use on the firewall's web interface.

### Option 1 — ACME / Let's Encrypt (Recommended)

ACME certificates are issued by a public CA (Let's Encrypt) and are the recommended approach for firewalls with a publicly resolvable hostname. You must configure ACME on the firewall first:

- **pfSense:** Install the **ACME** package (*System → Package Manager*), configure an account, add a certificate with your firewall's FQDN, and set it as the WebGUI certificate under *System → Advanced → Admin Access*.
- **OPNsense:** Use the built-in **Let's Encrypt** plugin (*System → Trust → ACME Client*) to issue a certificate, then select it under *System → Settings → Administration*.

Once an ACME certificate is active on the firewall, **no extra steps are needed in PFCentral.** The certificate is signed by a public CA that PFCentral already trusts — simply enter the firewall URL using its public hostname (e.g. `https://fw.example.com`) and proceed normally.

---

### Option 2 — Commercially Signed Certificate

Any certificate signed by a publicly trusted Certificate Authority (DigiCert, Sectigo, etc.) works without additional configuration, the same as ACME. Enter the firewall URL and proceed normally.

---

### Option 3 — Self-Signed or Private CA Certificate

Firewalls using a self-signed certificate or a certificate issued by an internal/private CA require **certificate enrollment** in PFCentral. Without enrollment, connections will fail with a TLS error such as:

```
cURL error 60: SSL certificate problem: self-signed certificate in certificate chain
```

PFCentral stores the certificate's public key and enforces it on every subsequent connection. If the key ever changes unexpectedly, the connection is refused — providing stronger protection than simply disabling TLS verification.

#### Requirements for the firewall certificate

The certificate must have a **Subject Alternative Name (SAN)** or **Common Name (CN)** that matches the hostname or IP address in the Firewall URL you configure in PFCentral. A certificate without a matching hostname/SAN will be rejected by TLS hostname verification even after enrollment.

> **Using a bare IP address (e.g. `https://192.0.2.1`)?**
> The certificate must include that IP address as an IP SAN (`IP:192.0.2.1`), not just as the CN. Most firewall web GUIs do not add IP SANs to auto-generated certificates. Using a hostname is strongly recommended.

#### Step 1 — Create a private CA and certificate on the firewall

**pfSense:**
1. Go to **System → Cert. Manager → CAs** → click **Add**. Fill in the descriptive name, key type (RSA 2048 or higher), and lifetime, then click **Save** to create your internal CA.
2. Go to **System → Cert. Manager → Certificates** → click **Add/Sign**. Choose *Create an internal certificate*, select your CA, set the **Common Name** to the firewall's hostname (e.g. `fw.example.com`) or IP address, add the same value as an **Alternative Name** (type `DNS` for a hostname, `IP` for an IP address), then **Save**.
3. Go to **System → Advanced → Admin Access**. Under *SSL/TLS Certificate*, select the certificate you just created, then **Save** and accept the browser warning on your next login.

**OPNsense:**
1. Go to **System → Trust → Authorities** → click **Add**. Fill in the name, method (*Create an internal Certificate Authority*), key size, and lifetime, then **Save**.
2. Go to **System → Trust → Certificates** → click **Add**. Choose *Create an internal Certificate*, select your CA, set the **Common Name** to the firewall hostname or IP, add the same value as an **Alternative Name**, then **Save**.
3. Go to **System → Settings → Administration**. Under *SSL Certificate*, select your new certificate, **Save**, and **Apply**.

#### Step 2 — Export the certificate

**pfSense:** System → Cert. Manager → Certificates → click the **Export Certificate** icon (⬇) next to your certificate. Save the `.crt` file.

**OPNsense:** System → Trust → Certificates → click the **Export** icon next to your certificate, choose *Certificate only*. Save the `.crt` file.

> **Important:** Export only the *public certificate* (`.pem` / `.crt`). Never export or upload the private key.

#### Step 3 — Enroll the certificate in PFCentral

1. In PFCentral, navigate to **Firewalls** and click **Edit** on the firewall, or click **Add Firewall** for a new one.
2. In the **API Connection** section, find the **"Trust a native or self-signed HTTPS certificate"** panel.
3. Click **Choose File** and select the `.crt` file exported in Step 2.
4. Re-enter the API credentials (required whenever the trust anchor changes).
5. Click **Save**. PFCentral will extract the certificate's public key, store it, and immediately verify connectivity.

From this point on, PFCentral will only connect to that firewall if the public key matches the enrolled value. Certificate renewals using the same key (e.g. extended lifetime with the same RSA/ECDSA key pair) continue to work without re-enrollment.

#### Changing or removing an enrolled certificate

To update the enrolled key (e.g. after generating a new key pair), edit the firewall and upload the new certificate. You will be required to re-enter API credentials. To stop using certificate pinning entirely (switching to a public CA cert), clear the *Advanced: trusted public-key fingerprint* field and leave the file upload empty, then re-enter credentials and save.

---

## Troubleshooting

### "403 Forbidden" on Uploaded Images (Logo/Favicon)
If you encounter a 403 error for uploaded images, it usually means the webserver cannot find the file in the `public/storage` directory, or it is looking in the wrong place.

**Common Causes:**
1.  **Missing Symlink**: The `public/storage` symbolic link is missing.
    *   **Fix**: Run `php artisan storage:link`.
2.  **Wrong Disk**: The application was saving files to `storage/app/private` (default) instead of `storage/app/public`.
    *   **Fix**: Update `SystemCustomizationController.php` to use `store('path', 'public')` (This is fixed in the latest codebase).
3.  **Permissions**: The webserver user (e.g., `www-data` or `nginx`) does not have permission to read the storage folder.
    *   **Fix**: `chmod -R 775 storage/app/public` and ensure ownership is correct.

**Verification:**
Check if the file actually exists where the symlink points:
```bash
ls -la storage/app/public/customization/
```
If the file is missing there but you have a URL, the upload process likely failed or saved to the wrong disk.

