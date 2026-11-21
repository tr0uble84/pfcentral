# ⬆️ Upgrades

An upgrade centre for pfSense: every firewall is checked first, its configuration is backed up, and upgrades go
out in waves.

```mermaid
flowchart LR
    A["🔎 Check<br/>nightly and on demand"] --> B["Plan<br/>wave 1 = test · wave 2 = the rest"]
    B --> C["Per firewall:<br/>check again"]
    C --> D["💾 Encrypted config backup<br/>(downloadable)"]
    D --> E["Start the upgrade"]
    E --> F{"Back on the new<br/>version within an hour?"}
    F -->|yes| G(["✔ Done"])
    F -->|no| H(["⚠ Flagged for a look"])
    G --> I["REST API package<br/>reinstalled (with SSH)<br/>or one command shown"]

    classDef core fill:#0E2238,stroke:#33C5F3,color:#ffffff;
    classDef good fill:#E8F7FD,stroke:#33C5F3,color:#0E2238;
    classDef bad fill:#FDECEC,stroke:#d73a4a,color:#5a0d12;
    class A,B,C,D,E core;
    class G good;
    class H bad;
```

## What the check looks at

- the firewall's version, and whether a newer one is offered
- free disk space
- saved configuration versions
- the way back (ZFS boot environments)
- the REST API package

Each wave is started by hand, and only after the earlier waves finished well.

> **Note:** a pfSense upgrade removes the REST API package. With SSH set up for the firewall, PFCentral reinstalls
> it by itself; otherwise the page shows the one command to run, then **Check again**.

Next: **[VPN Access →](VPN-Access)**
