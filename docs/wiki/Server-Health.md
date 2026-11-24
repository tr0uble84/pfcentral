# 🩺 Server health

**System → Server health** (global admins) shows the PFCentral server itself: the machine PFCentral runs on, not
your firewalls. Every line has a status and, when something is wrong, what to do about it.

```mermaid
flowchart LR
    subgraph checks ["Checked every time you open the page"]
        D["💽 Disk space"]
        M["🧠 Memory and load"]
        DB["🗄️ Database size"]
        H["📜 MySQL change history"]
        R["⚡ Redis accepts writes"]
        C["📦 Where the cache is kept"]
        Q["📬 Job queue and failed jobs"]
        S["⏱️ Scheduled tasks ran"]
        W["👷 Queue worker ran"]
        T["🧹 Maintenance timer ran"]
        L["📄 PFCentral log size"]
    end
    checks --> P{"Any problem?"}
    P -- "disk 85%+, Redis refusing writes,<br/>scheduled tasks stopped" --> B(["🔴 Banner on every page<br/>for global admins"])
    P -- "no" --> OK(["✅ All OK"])

    classDef core fill:#0E2238,stroke:#33C5F3,color:#ffffff;
    classDef good fill:#E8F7FD,stroke:#33C5F3,color:#0E2238;
    classDef bad fill:#FDECEC,stroke:#d73a4a,color:#5a0d12;
    class D,M,DB,H,R,C,Q,S,W,T,L core;
    class OK good;
    class B bad;
```

## What keeps the disk from filling up

| What | When | What it does |
| :-- | :-- | :-- |
| **Maintenance timer** (root) | every 6 hours, and 15 minutes after a reboot | Keeps one day of MySQL change history (skipped when another server copies the database), caps the system journal at 300 MB, removes PFCentral logs older than 14 days, trims `laravel.log` above 200 MB, clears the package cache, removes more history when the disk is still 95% full, and restarts Redis if it stops answering |
| **Nightly cleanup** (PFCentral) | 03:45 every night | Removes expired cache rows and sessions, failed jobs older than 30 days, finished job batches and old dated logs |
| **Cache in Redis** | since v0.21 | The cache changes every few seconds. In MySQL, every change also went into the change history, which filled a 24 GB disk in about two weeks |

The page has **Run maintenance now** and **Clean up PFCentral data** buttons for both.

## Installing the maintenance timer

New installations set it up. On a server installed before v0.21, run once:

```bash
sudo bash /var/www/pfcentral/scripts/install-maintenance.sh
```

Use your own install folder if it isn't `/var/www/pfcentral`. Running it again is safe. Its log is
`/var/log/pfcentral-maintenance.log`, and its settings are in `/etc/pfcentral-maintenance.conf`:

| Setting | Default | Meaning |
| :-- | :-- | :-- |
| `KEEP_BINLOG_HOURS` | `24` | Hours of MySQL change history to keep |
| `WARN_PCT` | `80` | Disk use that counts as a warning |
| `TEAMS_WEBHOOK_URL` | empty | A Microsoft Teams workflow webhook that gets the warnings |

> Keeping one day of MySQL change history means you can rewind the database to any point in the last day, not the
> last month. Your normal backups aren't affected.
