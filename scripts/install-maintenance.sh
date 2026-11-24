#!/bin/bash
# Installs (or updates) PFCentral's server maintenance timer. Run once as root:
#   sudo bash scripts/install-maintenance.sh
# Safe to run again: it keeps your settings in /etc/pfcentral-maintenance.conf.
set -euo pipefail

[ "$(id -u)" -eq 0 ] || { echo "Run this as root: sudo bash $0" >&2; exit 1; }
HERE="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
APP_DIR="${APP_DIR:-$(dirname "$HERE")}"
SRC="$HERE/maintenance"
CONF=/etc/pfcentral-maintenance.conf

for f in pfcentral-maintenance.sh pfcentral-maintenance.service pfcentral-maintenance.timer; do
  [ -f "$SRC/$f" ] || { echo "Missing $SRC/$f" >&2; exit 1; }
done

install -m 750 "$SRC/pfcentral-maintenance.sh" /usr/local/sbin/pfcentral-maintenance.sh
install -m 644 "$SRC/pfcentral-maintenance.service" /etc/systemd/system/pfcentral-maintenance.service
install -m 644 "$SRC/pfcentral-maintenance.timer" /etc/systemd/system/pfcentral-maintenance.timer

if [ ! -f "$CONF" ]; then
  umask 077
  cat > "$CONF" <<CONF
# PFCentral maintenance settings (read by /usr/local/sbin/pfcentral-maintenance.sh)
APP_DIR="$APP_DIR"
# KEEP_BINLOG_HOURS=24
# WARN_PCT=80
# Optional: a Microsoft Teams workflow webhook for disk warnings
# TEAMS_WEBHOOK_URL=""
CONF
elif ! grep -q '^APP_DIR=' "$CONF"; then
  echo "APP_DIR=\"$APP_DIR\"" >> "$CONF"
fi
chmod 600 "$CONF"

# Let PFCentral's web user start this one script (System -> Server health -> Run now).
WEB_USER="${WEB_USER:-$(stat -c %U "$APP_DIR/storage" 2>/dev/null || echo www-data)}"
if [ -n "$WEB_USER" ] && [ "$WEB_USER" != "root" ] && id "$WEB_USER" >/dev/null 2>&1; then
  tmp="$(mktemp)"
  echo "# PFCentral: run the server maintenance script (no arguments) from System -> Server health." > "$tmp"
  echo "$WEB_USER ALL=(root) NOPASSWD: /usr/local/sbin/pfcentral-maintenance.sh" >> "$tmp"
  if visudo -cf "$tmp" >/dev/null; then
    install -o root -g root -m 0440 "$tmp" /etc/sudoers.d/pfcentral-maintenance
  else
    echo "Skipped the sudo rule for $WEB_USER (it did not validate)." >&2
  fi
  rm -f "$tmp"
fi

systemctl daemon-reload
systemctl enable --now pfcentral-maintenance.timer >/dev/null
systemctl start pfcentral-maintenance.service || true

echo "PFCentral maintenance timer installed for $APP_DIR."
tail -n 8 /var/log/pfcentral-maintenance.log 2>/dev/null || true
systemctl list-timers pfcentral-maintenance.timer --no-pager 2>/dev/null | head -2 || true
