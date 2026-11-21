# ❓ FAQ

**Does PFCentral work with OPNsense?**
Yes, for day-to-day management. Deployments and the upgrade centre are pfSense-only for now
([roadmap](https://github.com/users/tr0uble84/projects/1)).

**Do my firewalls need anything installed?**
pfSense needs the [pfRest](https://github.com/pfrest/pfSense-pkg-RESTAPI) package. OPNsense uses its built-in API.

**Does PFCentral store my firewall passwords?**
It stores the API credentials it needs, encrypted. Technicians opening a firewall's web interface through
PFCentral get a temporary account and never see a firewall password.

**Can I try it without a domain?**
Yes: leave the domain empty and PFCentral uses the server's IP address over HTTP. Add a domain and HTTPS later.

**How do I update PFCentral?**
From the app: it updates itself from GitHub releases. After each update, a *What's new* window lists the changes.

**What happens if an install fails halfway?**
It stops at that step and tells you why. Fix it and run the installer with `--resume`: finished steps are skipped.
See **[Troubleshooting](Troubleshooting)**.

**How do I suggest a feature or report a bug?**
[Open an issue](https://github.com/tr0uble84/pfcentral/issues/new/choose). Pull requests are welcome too:
see [CONTRIBUTING](https://github.com/tr0uble84/pfcentral/blob/main/CONTRIBUTING.md).
