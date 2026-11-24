#!/bin/bash
# PFCentral server maintenance: keeps the disk from filling up.
# Installed to /usr/local/sbin by scripts/install-maintenance.sh and run every
# 6 hours by pfcentral-maintenance.timer. Safe to run by hand:
#   sudo /usr/local/sbin/pfcentral-maintenance.sh
# Log: /var/log/pfcentral-maintenance.log
# Status for System -> Server health: /var/lib/pfcentral/maintenance.json
set -u

APP_DIR="/var/www/pfcentral"     # overridden by /etc/pfcentral-maintenance.conf
KEEP_BINLOG_HOURS=24             # MySQL change history to keep
KEEP_APP_LOG_DAYS=14             # dated PFCentral logs to keep
MAX_APP_LOG_MB=200               # trim laravel.log above this size
JOURNAL_MAX=300M                 # systemd journal size cap
WARN_PCT=80                      # warn when the disk is this full
EMERGENCY_PCT=95                 # extra cleanup above this
TEAMS_WEBHOOK_URL=""             # optional: Teams workflow webhook for warnings
CONF=/etc/pfcentral-maintenance.conf
LOG=/var/log/pfcentral-maintenance.log
STATUS_DIR=/var/lib/pfcentral
STATUS="$STATUS_DIR/maintenance.json"

# shellcheck source=/dev/null
[ -f "$CONF" ] && . "$CONF"

NOTES=()
log()  { echo "$(date '+%F %T') $*" >> "$LOG"; logger -t pfcentral-maint -- "$*"; }
note() { log "$*"; NOTES+=("$*"); }
used_pct()   { df --output=pcent / | tail -1 | tr -dc '0-9'; }
used_bytes() { df --output=used -B1 / | tail -1 | tr -dc '0-9'; }
json_str()   { printf '%s' "$1" | sed 's/\\/\\\\/g; s/"/\\"/g'; }

alert() {
  log "ALERT: $*"
  [ -n "${TEAMS_WEBHOOK_URL:-}" ] || return 0
  local msg; msg=$(json_str "$*")
  curl -fsS -m 20 -H 'Content-Type: application/json' -d "{
    \"type\":\"message\",\"attachments\":[{\"contentType\":\"application/vnd.microsoft.card.adaptive\",
    \"content\":{\"type\":\"AdaptiveCard\",\"version\":\"1.4\",\"body\":[
      {\"type\":\"TextBlock\",\"weight\":\"Bolder\",\"text\":\"PFCentral server $(hostname)\"},
      {\"type\":\"TextBlock\",\"wrap\":true,\"text\":\"$msg\"}]}}]}" \
    "$TEAMS_WEBHOOK_URL" >/dev/null 2>&1 || log "could not send Teams alert"
}

started=$(date -u +%FT%TZ)
before=$(used_pct)
before_bytes=$(used_bytes)
log "start: disk ${before}% used"

# 1. MySQL change history (binlog). Only trimmed when no other server copies
#    this database (no replicas), so nothing that depends on it breaks.
replicas=-1
binlog_bytes=null
binlog_expire=null
if mysql -NBe 'SELECT 1' >/dev/null 2>&1; then
  replicas=$(mysql -NBe 'SHOW REPLICAS' 2>/dev/null | wc -l)
  if [ "$replicas" -eq 0 ]; then
    if mysql -e "SET PERSIST binlog_expire_logs_seconds = $((KEEP_BINLOG_HOURS * 3600));
                 PURGE BINARY LOGS BEFORE NOW() - INTERVAL $KEEP_BINLOG_HOURS HOUR;" 2>>"$LOG"; then
      log "MySQL history older than ${KEEP_BINLOG_HOURS}h removed"
    fi
  else
    note "MySQL has $replicas replica(s), so its change history was left alone."
  fi
else
  note "Could not connect to MySQL as root, so its change history was not trimmed."
fi

# 2. System journal
journalctl --vacuum-size="$JOURNAL_MAX" >/dev/null 2>&1

# 3. PFCentral logs: drop old dated logs, trim an oversized laravel.log
if [ -d "$APP_DIR/storage/logs" ]; then
  find "$APP_DIR/storage/logs" -maxdepth 1 -name '*.log' ! -name 'laravel.log' \
       -mtime +"$KEEP_APP_LOG_DAYS" -delete 2>/dev/null
  f="$APP_DIR/storage/logs/laravel.log"
  if [ -f "$f" ] && [ "$(du -m "$f" | cut -f1)" -gt "$MAX_APP_LOG_MB" ]; then
    tail -c 20M "$f" > "$f.tmp" && cat "$f.tmp" > "$f"; rm -f "$f.tmp"
    note "laravel.log was over ${MAX_APP_LOG_MB} MB, so only its last 20 MB were kept."
  fi
else
  note "PFCentral folder $APP_DIR not found, so its logs were not checked."
fi

# 4. Package download cache
apt-get clean >/dev/null 2>&1

# 5. Emergency: still nearly full, keep only 1 hour of MySQL history
now=$(used_pct)
if [ "$now" -ge "$EMERGENCY_PCT" ] && [ "$replicas" -eq 0 ]; then
  mysql -e 'PURGE BINARY LOGS BEFORE NOW() - INTERVAL 1 HOUR;' 2>>"$LOG" \
    && note "Disk was ${now}% full, so MySQL history older than 1 hour was removed."
  now=$(used_pct)
fi

# 6. Redis stops accepting writes after a failed save to a full disk.
#    If it no longer answers PONG, restart it once there is room again.
if [ "$(redis-cli ping 2>/dev/null)" != "PONG" ] && [ "$now" -lt "$EMERGENCY_PCT" ]; then
  systemctl restart redis-server && note "Redis was not answering, so it was restarted."
fi

if [ "$replicas" -ge 0 ]; then
  binlog_bytes=$(mysql -NBe 'SHOW BINARY LOGS' 2>/dev/null | awk '{s+=$2} END {print s+0}')
  binlog_expire=$(mysql -NBe 'SELECT @@binlog_expire_logs_seconds' 2>/dev/null || echo null)
fi
after_bytes=$(used_bytes)
freed=$(( before_bytes > after_bytes ? before_bytes - after_bytes : 0 ))
log "done: disk ${now}% used (was ${before}%)"
if [ "$now" -ge "$WARN_PCT" ]; then
  alert "Disk is ${now}% full after cleanup (warning level ${WARN_PCT}%). Check /var/log/pfcentral-maintenance.log."
  NOTES+=("Disk is still ${now}% full after cleanup.")
fi

# Status for the Server health page (no secrets in here).
mkdir -p "$STATUS_DIR" && chmod 755 "$STATUS_DIR"
notes_json=""
for n in "${NOTES[@]+"${NOTES[@]}"}"; do notes_json+="${notes_json:+,}\"$(json_str "$n")\""; done
cat > "$STATUS.tmp" <<JSON
{"started_at":"$started","finished_at":"$(date -u +%FT%TZ)","disk_pct_before":$before,"disk_pct_after":$now,"freed_bytes":$freed,"binlog_bytes":${binlog_bytes:-null},"binlog_expire_seconds":${binlog_expire:-null},"replicas":$replicas,"notes":[${notes_json}]}
JSON
chmod 644 "$STATUS.tmp" && mv -f "$STATUS.tmp" "$STATUS"
exit 0
