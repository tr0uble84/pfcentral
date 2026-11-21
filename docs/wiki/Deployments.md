# 📦 Deployments

Send **the same change to many pfSense firewalls at once**: checked first, rolled out in waves, saved as one
configuration change per firewall, and undone automatically if a firewall can't load it.

## The flow

```mermaid
flowchart TD
    A(["New deployment<br/>(or select firewalls → Deploy changes)"]) --> B["Add changes<br/>rules · aliases · NAT · DNS · routes · pfBlockerNG · settings"]
    B --> C["🔎 Pre-check every firewall<br/>version · packages · interfaces · aliases"]
    C --> D{"For each change"}
    D -->|new| D1["Will be added"]
    D -->|different| D2["Will be updated"]
    D -->|same| D3["Already set"]
    D -->|missing package| D4["Skipped: install it, then Retry"]
    D1 & D2 & D3 --> W1["Wave 1 · one test firewall<br/>now or scheduled"]
    W1 --> OK1{"Went well?"}
    OK1 -->|yes| W2["Wave 2 · the rest"]
    OK1 -->|no| STOP(["Later waves don't start"])
    W2 --> DONE(["✔ Done · undo any time"])

    subgraph fw ["On each firewall"]
      direction LR
      F1["Save all changes<br/>as ONE config change"] --> F2{"Firewall loads<br/>the new rules?"}
      F2 -->|yes| F3["✔ Applied"]
      F2 -->|no| F4["↩ Puts the previous<br/>settings back by itself"]
    end
    W1 -.-> fw
    W2 -.-> fw

    classDef core fill:#0E2238,stroke:#33C5F3,color:#ffffff;
    classDef good fill:#E8F7FD,stroke:#33C5F3,color:#0E2238;
    classDef bad fill:#FDECEC,stroke:#d73a4a,color:#5a0d12;
    class B,C,W1,W2,F1 core;
    class DONE,F3 good;
    class STOP,F4,D4 bad;
```

## What you can deploy

| Group | Changes |
| :-- | :-- |
| **Firewall** | Rules (top or bottom of an interface's list), floating rules, aliases, schedules |
| **NAT** | Port forwards (with their pass rule), outbound NAT, 1:1 NAT |
| **DNS and routing** | DNS Resolver host and domain overrides, static routes |
| **pfBlockerNG** | IPv4 and IPv6 lists, DNSBL groups |
| **System** | Tunables, DNS servers, time (NTP) servers, remote logging, time zone |

Every change can also **remove** an item (an old rule, alias or port forward) from many firewalls.

## Handy details

- **Same name, same change:** an alias or list with the same name, or a rule with the same description on the same
  interface, is updated to match. Nothing is duplicated.
- **Every internal interface:** enter `@internal` as the interface to put a rule on every LAN-type interface.
- **Copy from a firewall:** read one firewall's rules, aliases, NAT, DNS overrides, routes, pfBlockerNG lists and
  settings, tick what you want, and deploy them to others. Nothing on that firewall changes.
- **Undo** per firewall or for the whole deployment: removes what was added and puts back what was changed.
- **Who can deploy:** only the Microsoft 365 people and groups a global admin picks under
  **Deployments → Who can deploy**. Until someone is picked, nobody can deploy.

Next: **[Upgrades →](Upgrades)**
