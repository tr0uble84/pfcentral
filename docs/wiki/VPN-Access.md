# 🔐 VPN Access

Self-service **WireGuard remote access** for each company, with **Microsoft 365 (Entra ID)** sign-in or
email-and-password accounts with two-factor. PFCentral builds the tunnel on the company's firewall and keeps
access in step with who is allowed.

## How a person gets connected

```mermaid
sequenceDiagram
    autonumber
    actor P as Person
    participant S as Staff VPN page<br/>(its own host name)
    participant M as Microsoft 365
    participant C as PFCentral
    participant F as Company firewall<br/>(WireGuard)

    P->>S: Open the VPN page (or the Windows app)
    S->>M: Sign in with Microsoft 365
    M-->>S: Signed in
    S->>C: Is this person allowed? Which office?
    C-->>S: Yes: their access groups' firewalls
    P->>S: Pick an office, add this device
    C->>F: Add the device (its public key only)
    F-->>C: Added
    S-->>P: QR code, .conf file, or the app connects
    Note over C,F: Every 15 minutes PFCentral removes devices<br/>of people who lost access or left the company
```

## Setting up a company

```mermaid
flowchart LR
    A["Companies → company<br/>→ VPN Access"] --> B["Connect Microsoft 365<br/>(one click, or a link<br/>for their IT admin)"]
    B --> C["Firewalls → Enable VPN<br/>→ Create WireGuard"]
    C --> D["Give access<br/>people · invites · Microsoft 365 groups"]
    D --> E(["People connect"])

    classDef core fill:#0E2238,stroke:#33C5F3,color:#ffffff;
    classDef good fill:#E8F7FD,stroke:#33C5F3,color:#0E2238;
    class A,B,C,D core;
    class E good;
```

**Create WireGuard** builds the tunnel and its rules on the firewall: DNS to the firewall, the firewall's admin
pages blocked, the chosen LAN allowed, other private networks blocked, internet allowed, plus the WAN rule. On
pfSense it installs the WireGuard package first if it's missing. A tunnel set up by hand can be adopted instead.

## Features

- **Access groups:** for companies with several firewalls, groups decide who may use which firewall, and can be
  linked to Microsoft 365 groups.
- **Email-and-password accounts** for companies without Microsoft 365, with invitations, password links and
  optional or required two-factor.
- **Live connections:** who is connected right now, from where and with how much traffic, with **Disconnect** and
  **Block**.
- **Windows app:** sign-in, diagnostics and support tickets from inside the app.

Next: **[Security →](Security)**
