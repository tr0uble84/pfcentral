# Contributing to PFCentral

Thanks for helping. PFCentral is maintained by Jay Behi (@tr0uble84).

## How changes get in

1. **Fork** this repository and create a branch from `main` (for example `feature/limiters` or `fix/alias-import`).
2. Make your change. Keep one feature or fix per pull request.
3. Add or update tests in `tests/Feature`, and run the suite:
   ```
   composer install
   php artisan test
   ```
4. Add a line to `CHANGELOG.md` under `## [Unreleased]` describing the change in plain words.
5. Open a **pull request** to `tr0uble84/pfcentral:main` and fill in the template.

Every pull request is reviewed before it is merged. The maintainer may ask for changes. Merged work ships in the next
release, and installations update from signed releases of this repository.

## Rules for code

- Never commit secrets: no API keys, passwords, tokens, private keys, real customer data, real IP addresses or host
  names. Use documentation addresses (`192.0.2.0/24`, `198.51.100.0/24`, `203.0.113.0/24`) and `example.com`.
- Changes that touch firewalls must be safe by default: check first, change nothing on errors, and keep undo working.
- Follow the existing style (Laravel, Blade with Alpine.js, Tailwind). Keep user-facing text short and plain.

## Sign your work

By contributing you agree that your contribution can be distributed as part of PFCentral under this repository's
license. Add a `Signed-off-by` line to each commit (`git commit -s`), certifying the
[Developer Certificate of Origin](https://developercertificate.org/).
