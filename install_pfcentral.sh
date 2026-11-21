#!/usr/bin/env bash
# =============================================================================
#  PFCentral installer: a guided setup for a fresh Linux server.
#
#    curl -fsSL https://raw.githubusercontent.com/tr0uble84/pfcentral/main/install_pfcentral.sh -o install_pfcentral.sh
#    sudo bash install_pfcentral.sh
#
#  It asks a few questions, checks the server before changing anything, then
#  installs step by step. After every step it checks that the step worked and
#  saves a checkpoint, so if something fails you can fix it and continue where
#  it stopped:  sudo bash install_pfcentral.sh --resume
#
#  Options:
#    --resume       continue an installation that stopped
#    --check        only run the pre-checks, change nothing
#    --verbose      show every command's output while installing
#    --unattended   no questions; use the PFC_* settings below
#    --help
#
#  Settings for --unattended (or to skip a question):
#    PFC_DOMAIN=console.example.com   PFC_EMAIL=you@example.com
#    PFC_HTTPS=yes|no   PFC_REPORT=yes|no   INSTALL_DIR=/var/www/pfcentral
#    DB_USER=pfcentral  DB_PASS=...  (a strong password is generated if empty)
# =============================================================================
set -Eeuo pipefail

INSTALLER_VERSION="2"
REPO_SLUG="${REPO_SLUG:-tr0uble84/pfcentral}"
# Where error reports go. Reports are created as GitHub issues on REPO_SLUG
# (with the GitHub CLI when it's signed in), or sent to PFC_REPORT_URL when one
# is set (a relay that files the issue), otherwise saved with a ready-made link.
PFC_REPORT_URL="${PFC_REPORT_URL:-}"

STATE_DIR="/root/.pfcentral-install"
STATE_FILE="${STATE_DIR}/state"
LOG_FILE="/var/log/pfcentral-install.log"

MODE="install"; VERBOSE=0; UNATTENDED=0; RESUME=0
for arg in "$@"; do
  case "$arg" in
    --resume) RESUME=1 ;;
    --check) MODE="check" ;;
    --verbose) VERBOSE=1 ;;
    --unattended|--yes|-y) UNATTENDED=1 ;;
    -h|--help) sed -n '2,25p' "${BASH_SOURCE[0]:-$0}" 2>/dev/null | sed 's/^#  \{0,1\}//'; exit 0 ;;
    *) echo "Unknown option: $arg (try --help)"; exit 2 ;;
  esac
done

# ---------------- CONFIG (override via env) ----------------
# Ubuntu defaults to auto; PHP_VER=8.4 / PHP_VER=8.5 still selects that series.
UBUNTU_PHP_REQUEST="${PHP_VER:-auto}"
PHP_VER="${PHP_VER:-8.4}"
NODE_MAJOR="${NODE_MAJOR:-20}"

REPO_URL="${REPO_URL:-https://github.com/${REPO_SLUG}.git}"
INSTALL_DIR="${INSTALL_DIR:-/var/www/pfcentral}"
WEB_USER="${WEB_USER:-www-data}"
WEB_GROUP="${WEB_GROUP:-www-data}"

DB_NAME="${DB_NAME:-pfcentral}"
DB_HOST="${DB_HOST:-127.0.0.1}"
DB_PORT="${DB_PORT:-3306}"
DB_USER="${DB_USER:-pfcentral}"
DB_PASS="${DB_PASS:-}"

PFC_DOMAIN="${PFC_DOMAIN:-}"
PFC_EMAIL="${PFC_EMAIL:-}"
PFC_HTTPS="${PFC_HTTPS:-}"
PFC_REPORT="${PFC_REPORT:-}"
# -----------------------------------------------------------

OS_FAMILY=""
DB_SERVICE_NAME="mysql"
RUNTIME_WEB_USER=""
RUNTIME_WEB_GROUP=""
WEB_HOME=""
PHP_SERVICE=""
PUBLIC_IP=""
PFC_SERVER_NAME="_"
APP_VERSION="unknown"
CURRENT_STEP=""
CURRENT_TITLE=""
FAIL_MSG=""
FAIL_CMD=""
FAIL_LINE=""
EXPECTED_EXIT=""
TICKER_PID=""

# ---------------- screen output ----------------
if [[ -t 1 ]] && [[ "${TERM:-dumb}" != "dumb" ]]; then
  C_B=$'\e[1m'; C_D=$'\e[2m'; C_G=$'\e[32m'; C_R=$'\e[31m'; C_Y=$'\e[33m'; C_C=$'\e[36m'; C_0=$'\e[0m'
else
  C_B=""; C_D=""; C_G=""; C_R=""; C_Y=""; C_C=""; C_0=""
fi
OK="${C_G}✔${C_0}"; NO="${C_R}✖${C_0}"; WARN="${C_Y}!${C_0}"

# fd 3 = the screen, stdout/stderr = the log (and the screen with --verbose).
exec 3>&1
mkdir -p "$(dirname "$LOG_FILE")" 2>/dev/null || true
say()  { printf '%s\n' "$*" >&3; printf '%s\n' "$*" | sed 's/\x1b\[[0-9;]*m//g' >>"$LOG_FILE" 2>/dev/null || true; }
log()  { echo -e "\n[+] $*\n"; }
die()  { FAIL_MSG="$*"; echo -e "\n[X] $*\n"; exit 1; }
hr()   { say "${C_D}────────────────────────────────────────────────────────────────${C_0}"; }

ask() { # ask "Question" "default" -> REPLY
  local q="$1" def="${2:-}"
  if [[ $UNATTENDED -eq 1 ]]; then REPLY="$def"; return 0; fi
  if [[ -n "$def" ]]; then printf '%s [%s]: ' "$q" "$def" >&3; else printf '%s: ' "$q" >&3; fi
  IFS= read -r REPLY </dev/tty || REPLY=""
  REPLY="${REPLY:-$def}"
}
yesno() { # yesno "Question" y|n -> 0 for yes
  local q="$1" def="${2:-y}" hint="Y/n"
  [[ "$def" == "n" ]] && hint="y/N"
  if [[ $UNATTENDED -eq 1 ]]; then [[ "$def" == "y" ]]; return; fi
  while true; do
    printf '%s [%s]: ' "$q" "$hint" >&3
    IFS= read -r REPLY </dev/tty || REPLY=""
    REPLY="${REPLY:-$def}"
    case "${REPLY,,}" in y|yes) return 0 ;; n|no) return 1 ;; esac
  done
}

banner() {
  say ""
  say "${C_C}${C_B}   ____  _____ ____           _             _ ${C_0}"
  say "${C_C}${C_B}  |  _ \\|  ___/ ___|___ _ __ | |_ _ __ __ _| |${C_0}"
  say "${C_C}${C_B}  | |_) | |_ | |   / _ \\ '_ \\| __| '__/ _\` | |${C_0}"
  say "${C_C}${C_B}  |  __/|  _|| |__|  __/ | | | |_| | | (_| | |${C_0}"
  say "${C_C}${C_B}  |_|   |_|   \\____\\___|_| |_|\\__|_|  \\__,_|_|${C_0}"
  say ""
  say "  ${C_B}PFCentral installer${C_0} ${C_D}(installer ${INSTALLER_VERSION}, from ${REPO_SLUG})${C_0}"
  say "  Central management for your pfSense firewalls."
  say ""
}

# ---------------- state (checkpoints) ----------------
state_get() { [[ -f "$STATE_FILE" ]] && sed -n "s/^$1=//p" "$STATE_FILE" | tail -n1 || true; }
state_set() {
  mkdir -p "$STATE_DIR"; chmod 700 "$STATE_DIR"
  touch "$STATE_FILE"; chmod 600 "$STATE_FILE"
  local tmp; tmp="$(mktemp "${STATE_DIR}/.s.XXXXXX")"
  grep -v "^$1=" "$STATE_FILE" >"$tmp" || true
  printf '%s=%s\n' "$1" "$2" >>"$tmp"
  mv "$tmp" "$STATE_FILE"; chmod 600 "$STATE_FILE"
}
step_done() { [[ "$(state_get "done_$1")" == "1" ]]; }
save_answers() {
  local k
  for k in PFC_DOMAIN PFC_EMAIL PFC_HTTPS PFC_REPORT INSTALL_DIR DB_USER DB_PASS DB_NAME PHP_VER PHP_SERVICE \
           RUNTIME_WEB_USER RUNTIME_WEB_GROUP WEB_HOME APP_VERSION PUBLIC_IP; do
    state_set "$k" "${!k}"
  done
}
load_answers() {
  local k v
  for k in PFC_DOMAIN PFC_EMAIL PFC_HTTPS PFC_REPORT INSTALL_DIR DB_USER DB_PASS DB_NAME PHP_VER PHP_SERVICE \
           RUNTIME_WEB_USER RUNTIME_WEB_GROUP WEB_HOME APP_VERSION PUBLIC_IP; do
    v="$(state_get "$k")"; [[ -n "$v" ]] && printf -v "$k" '%s' "$v"
  done
  return 0
}

# ---------------- error reports ----------------
urlencode() {
  local LC_ALL=C s="$1" out="" c i
  for (( i=0; i<${#s}; i++ )); do
    c="${s:i:1}"
    case "$c" in [a-zA-Z0-9.~_-]) out+="$c" ;; *) printf -v c '%%%02X' "'$c"; out+="$c" ;; esac
  done
  printf '%s' "$out"
}
json_str() { # JSON-escape a string
  local s="$1"
  s="${s//\\/\\\\}"; s="${s//\"/\\\"}"; s="${s//$'\t'/\\t}"; s="${s//$'\r'/}"; s="${s//$'\n'/\\n}"
  s="$(printf '%s' "$s" | tr -d '\000-\010\013\014\016-\037')"
  printf '"%s"' "$s"
}
scrub() { # remove passwords, keys, the domain, the email and IP addresses
  local text; text="$(cat)"
  [[ -n "${DB_PASS:-}" ]] && text="${text//"$DB_PASS"/[password]}"
  [[ -n "${PFC_DOMAIN:-}" ]] && text="${text//"$PFC_DOMAIN"/[domain]}"
  [[ -n "${PFC_EMAIL:-}" ]] && text="${text//"$PFC_EMAIL"/[email]}"
  printf '%s' "$text" | sed -E \
    -e 's/(base64:)[A-Za-z0-9+\/=]{20,}/\1[key]/g' \
    -e 's/((PASS(WORD)?|SECRET|TOKEN|KEY)[A-Z_]*[=:][[:space:]]*)[^[:space:]]+/\1[hidden]/Ig' \
    -e 's/(IDENTIFIED BY )'"'"'[^'"'"']*'"'"'/\1[hidden]/Ig' \
    -e 's/\b([0-9]{1,3}\.){3}[0-9]{1,3}\b/x.x.x.x/g' \
    -e 's/[A-Za-z0-9._%+-]+@[A-Za-z0-9.-]+\.[A-Za-z]{2,}/[email]/g'
}
system_line() {
  local os="unknown" mem
  [[ -f /etc/os-release ]] && os="$(. /etc/os-release; echo "${PRETTY_NAME:-$ID}")"
  mem="$(awk '/MemTotal/{printf "%.1f GB", $2/1048576}' /proc/meminfo 2>/dev/null || echo "?")"
  printf '%s, %s, %s RAM, %s CPU' "$os" "$(uname -m)" "$mem" "$(nproc 2>/dev/null || echo '?')"
}

send_report() {
  local step="$1" detail="$2"
  local sys ver title tail_log body report file url
  sys="$(system_line)"
  ver="installer ${INSTALLER_VERSION}, PFCentral ${APP_VERSION}"
  title="Installer error: ${step}"
  tail_log="$(tail -n 60 "$LOG_FILE" 2>/dev/null | sed 's/\x1b\[[0-9;]*m//g' || true)"
  report="$(printf 'Error: %s\nCommand: %s\n\n--- last lines of the install log ---\n%s\n' "$detail" "${FAIL_CMD:-}" "$tail_log" | scrub)"
  file="${STATE_DIR}/error-report-$(date +%Y%m%d-%H%M%S).txt"
  mkdir -p "$STATE_DIR"
  printf 'Step: %s\nSystem: %s\nVersion: %s\n\n%s\n' "$step" "$sys" "$ver" "$report" >"$file"
  chmod 600 "$file"

  body="$(printf '### Step that failed\n%s\n\n### System\n%s\n\n### Installer and PFCentral version\n%s\n\n### What the installer showed\n```text\n%s\n```\n\n_Sent automatically by the PFCentral installer._\n' "$step" "$sys" "$ver" "$report")"

  # 1) A relay that files the issue for us.
  if [[ -n "$PFC_REPORT_URL" ]] && command -v curl >/dev/null 2>&1; then
    if curl -fsS -m 20 -H 'Content-Type: application/json' \
         -d "{\"title\":$(json_str "$title"),\"body\":$(json_str "$body"),\"labels\":[\"installer-error\"]}" \
         "$PFC_REPORT_URL" >/dev/null 2>&1; then
      say "  ${OK} Error report sent to the PFCentral developers."
      return 0
    fi
  fi
  # 2) The GitHub CLI, when it's signed in on this server.
  if command -v gh >/dev/null 2>&1 && gh auth status >/dev/null 2>&1; then
    if url="$(gh issue create -R "$REPO_SLUG" --title "$title" --body "$body" --label installer-error 2>/dev/null \
              || gh issue create -R "$REPO_SLUG" --title "$title" --body "$body" 2>/dev/null)"; then
      say "  ${OK} Error report filed on GitHub: ${url}"
      return 0
    fi
  fi
  # 3) A link that opens the report, already filled in, on GitHub.
  local short; short="$(printf '%s' "$report" | tail -c 2500)"
  url="https://github.com/${REPO_SLUG}/issues/new?template=installer-error.yml"
  url+="&title=$(urlencode "$title")&step=$(urlencode "$step")&system=$(urlencode "$sys")"
  url+="&version=$(urlencode "$ver")&error=$(urlencode "$short")"
  say "  ${WARN} The report couldn't be sent from this server, so it's ready to send in one click."
  say "    Open this link (signed in to GitHub) and click ${C_B}Submit${C_0}:"
  say ""
  say "    ${C_C}${url}${C_0}"
  say ""
  say "  Report saved to ${file}"
}

# ---------------- failure handling ----------------
on_err() { FAIL_CMD="${BASH_COMMAND:-}"; FAIL_LINE="${1:-?}"; }
trap 'on_err $LINENO' ERR

stop_ticker() {
  if [[ -n "${TICKER_PID:-}" ]]; then kill "$TICKER_PID" 2>/dev/null || true; wait "$TICKER_PID" 2>/dev/null || true; TICKER_PID=""; printf '\r\033[K' >&3; fi
}

on_exit() {
  local rc=$?
  stop_ticker
  [[ $rc -eq 0 || -n "${EXPECTED_EXIT:-}" ]] && return 0
  if [[ -z "$CURRENT_STEP" ]]; then
    # Stopped outside a step (while checking or asking): never silently.
    say ""
    say "  ${NO} ${C_R}${C_B}The installer stopped unexpectedly${C_0} at line ${FAIL_LINE:-?}: ${FAIL_MSG:-${FAIL_CMD:-unknown command}}"
    say "     Nothing was installed. Details: ${LOG_FILE}"
    [[ "${PFC_REPORT:-no}" == "yes" ]] && { say ""; send_report "before installing (line ${FAIL_LINE:-?})" "${FAIL_MSG:-${FAIL_CMD:-unknown}}" || true; }
    say ""
    return 0
  fi
  [[ "$MODE" == "check" ]] && return 0
  state_set "failed_step" "$CURRENT_STEP"
  local detail="${FAIL_MSG:-the command \"${FAIL_CMD:-unknown}\" failed (exit code ${rc})}"
  local last; last="$(grep -v '^---- checking\|^\[X\] check failed' "$LOG_FILE" 2>/dev/null | sed 's/\x1b\[[0-9;]*m//g' | grep -v '^[[:space:]]*$' | tail -n 12 || true)"
  say ""
  say "  ${NO} ${C_R}${C_B}Step ${CURRENT_TITLE} failed.${C_0}"
  say "     ${detail}"
  say ""
  say "  ${C_B}Last lines of the log${C_0} ${C_D}(${LOG_FILE})${C_0}:"
  printf '%s\n' "$last" | sed 's/^/    /' >&3
  say ""
  if [[ "${PFC_REPORT:-no}" == "yes" ]]; then
    send_report "$CURRENT_TITLE" "$detail" || true
    say ""
  fi
  hr
  say "  Nothing after this step was changed. Fix the problem above, then continue with:"
  say ""
  say "    ${C_B}sudo bash ${STATE_DIR}/install_pfcentral.sh --resume${C_0}"
  say ""
  if [[ $UNATTENDED -eq 0 && -r /dev/tty ]] && yesno "  Try this step again now?" n; then
    local again=(--resume); [[ $VERBOSE -eq 1 ]] && again+=(--verbose)
    exec bash "${STATE_DIR}/install_pfcentral.sh" "${again[@]}"
  fi
}
trap on_exit EXIT

# ---------------- running a step ----------------
STEP_IDS=(packages database download configure composer appsetup frontend privileges webserver services https health)
declare -A STEP_TITLE=(
  [packages]="Software (web server, PHP, database, Redis, Node.js)"
  [database]="Database and its user"
  [download]="Download PFCentral"
  [configure]="Settings (.env)"
  [composer]="PHP libraries (Composer)"
  [appsetup]="App key and database tables"
  [frontend]="Build the web pages"
  [privileges]="Permissions and safe admin helpers"
  [webserver]="Web server (Nginx) and firewall ports"
  [services]="Background workers, live updates, scheduler"
  [https]="HTTPS certificate (Let's Encrypt)"
  [health]="Final check"
)

run_step() {
  local id="$1" n="$2" total="$3"
  CURRENT_STEP="$id"
  CURRENT_TITLE="${n} of ${total}: ${STEP_TITLE[$id]}"
  if step_done "$id"; then
    say "  ${OK} ${C_D}${CURRENT_TITLE} (done earlier)${C_0}"
    return 0
  fi
  say "  ${C_C}▸${C_0} ${CURRENT_TITLE}"
  echo; echo "================ STEP ${CURRENT_TITLE} ================"
  if [[ $VERBOSE -eq 0 && -t 3 ]]; then
    ( tail -n0 -F "$LOG_FILE" 2>/dev/null | while IFS= read -r line; do
        line="$(printf '%s' "$line" | sed 's/\x1b\[[0-9;]*m//g' | tr -d '\r')"
        [[ -n "$line" ]] && printf '\r\033[K    %s%s%s' "$C_D" "${line:0:72}" "$C_0" >&3
      done ) &
    TICKER_PID=$!
  fi
  "step_${id}"
  stop_ticker
  echo "---- checking: ${STEP_TITLE[$id]}"
  "verify_${id}"
  stop_ticker
  state_set "done_${id}" 1
  save_answers
  say "  ${OK} ${CURRENT_TITLE}"
}

check_fail() { FAIL_MSG="$*"; echo "[X] check failed: $*"; return 1; }
os_detect() {
  if [[ -f /etc/os-release ]]; then
    . /etc/os-release
    local id_like="${ID_LIKE:-}"
    local id="${ID:-}"

    if [[ "$id" == "debian" || "$id" == "ubuntu" || "$id_like" == *"debian"* || "$id_like" == *"ubuntu"* ]]; then
      OS_FAMILY="debian"
      DB_SERVICE_NAME="mysql"
      PHP_SERVICE="php${PHP_VER}-fpm"
    elif [[ "$id" == "fedora" || "$id" == "rhel" || "$id" == "centos" || "$id_like" == *"fedora"* || "$id_like" == *"rhel"* || "$id_like" == *"centos"* ]]; then
      OS_FAMILY="redhat"
      DB_SERVICE_NAME="mariadb"
      PHP_SERVICE="php-fpm"
      if [[ "${WEB_USER}" == "www-data" ]]; then WEB_USER="nginx"; fi
      if [[ "${WEB_GROUP}" == "www-data" ]]; then WEB_GROUP="nginx"; fi
    elif [[ "$id" == "arch" || "$id_like" == *"arch"* ]]; then
      OS_FAMILY="arch"
      DB_SERVICE_NAME="mariadb"
      PHP_SERVICE="php-fpm"
      if [[ "${WEB_USER}" == "www-data" ]]; then WEB_USER="http"; fi
      if [[ "${WEB_GROUP}" == "www-data" ]]; then WEB_GROUP="http"; fi
    elif [[ "$id" == "suse" || "$id" == opensuse* || "$id_like" == *"suse"* ]]; then
      OS_FAMILY="suse"
      DB_SERVICE_NAME="mariadb"
      PHP_SERVICE="php8-fpm"
      if [[ "${WEB_USER}" == "www-data" ]]; then WEB_USER="nginx"; fi
      if [[ "${WEB_GROUP}" == "www-data" ]]; then WEB_GROUP="nginx"; fi
    else
      log "Warning: Unknown OS $id or $id_like, defaulting to debian strategy"
      OS_FAMILY="debian"
      DB_SERVICE_NAME="mysql"
      PHP_SERVICE="php${PHP_VER}-fpm"
    fi
  else
    die "Cannot detect OS: /etc/os-release missing"
  fi
}

wait_for_pkg_locks() {
  if [[ "$OS_FAMILY" == "debian" ]]; then
    log "Waiting for package manager locks to clear"
    local i=0
    while fuser /var/lib/dpkg/lock-frontend >/dev/null 2>&1 \
       || fuser /var/lib/apt/lists/lock >/dev/null 2>&1 \
       || fuser /var/cache/apt/archives/lock >/dev/null 2>&1; do
      i=$((i+1))
      [[ $i -le 300 ]] || die "apt locks did not clear after ~10 minutes"
      sleep 2
    done
  fi
}

pkg_install() {
  wait_for_pkg_locks
  case "$OS_FAMILY" in
    debian)
      DEBIAN_FRONTEND=noninteractive apt-get install -y --no-install-recommends "$@"
      ;;
    redhat)
      dnf install -y "$@"
      ;;
    arch)
      pacman -S --noconfirm --needed "$@"
      ;;
    suse)
      zypper install -y --no-recommends "$@"
      ;;
  esac
}

# Ubuntu additions: the other distribution paths below are unchanged.
# Auto mode follows the newest stable PHP 8.x available from APT, not beta/RC
# builds or a future major version. The production lock file is checked later;
# package availability alone is not a claim of application compatibility.
ubuntu_php_candidate() {
  LC_ALL=C apt-cache policy "$1" \
    | awk '/^[[:space:]]*Candidate:/ {print $2}'
}

ubuntu_php_packages_usable() {
  local series="$1" package candidate upstream simulation
  local -a packages=()
  local suffix

  for suffix in cli fpm mysql mbstring xml curl zip gd bcmath intl redis; do
    package="php${series}-${suffix}"
    candidate="$(ubuntu_php_candidate "$package")" || return 1
    if [[ -z "$candidate" || "$candidate" == "(none)" ]]; then
      log "Ubuntu: skipping PHP ${series}; ${package} has no APT candidate"
      return 1
    fi

    if [[ "$suffix" == "cli" || "$suffix" == "fpm" ]]; then
      # Match a stable upstream x.y.z, optionally with a Debian epoch/revision.
      # Examples rejected here: 8.6.0beta1, 8.6.0~rc1, 8.6.0+git20260901.
      if [[ ! "$candidate" =~ ^([0-9]+:)?([0-9]+\.[0-9]+\.[0-9]+)([-+].*)?$ ]] \
          || [[ "${candidate,,}" =~ (alpha|beta|rc[0-9]*|dev|git|snapshot) ]]; then
        log "Ubuntu: skipping non-stable ${package} candidate ${candidate}"
        return 1
      fi
      upstream="${candidate#*:}"
      upstream="${upstream%%[-+]*}"
      # The dependency set reported during this install requires >= 8.4.1.
      # This is a lower bound, not a pin to the PHP 8.4 series.
      if ! dpkg --compare-versions "$upstream" ge 8.4.1; then
        log "Ubuntu: skipping ${package} ${upstream}; PHP >= 8.4.1 is required"
        return 1
      fi
    fi
    packages+=("$package")
  done

  # Check package dependencies without changing the machine. Redis must be
  # available for this series too; do not mix a generic Redis PHP metapackage
  # from another PHP series into this selection.
  if ! simulation="$(LC_ALL=C apt-get --simulate --no-install-recommends \
      install "${packages[@]}" 2>&1)"; then
    log "Ubuntu: PHP ${series} packages cannot currently be installed"
    printf '%s\n' "$simulation"
    return 1
  fi
  return 0
}

prepare_ubuntu_php() {
  [[ "${ID:-}" == "ubuntu" ]] || return 0
  local requested="$UBUNTU_PHP_REQUEST" attempt listing versions series
  local -a candidates=()

  [[ "$requested" == "auto" || "$requested" =~ ^[0-9]+\.[0-9]+$ ]] \
    || die "On Ubuntu, PHP_VER must be auto or a major.minor series such as 8.4 or 8.5"

  # Try the currently configured repositories first. Only add the PPA when
  # they cannot provide the requested/eligible runtime and its extensions.
  for attempt in 0 1; do
    if [[ "$requested" == "auto" ]]; then
      listing="$(LC_ALL=C apt-cache pkgnames)" \
        || die "Could not read Ubuntu's APT package index"
      versions="$(printf '%s\n' "$listing" \
        | sed -nE 's/^php(8\.[0-9]+)-cli$/\1/p' | sort -uVr)"
      mapfile -t candidates <<< "$versions"
    else
      candidates=("$requested")
    fi

    for series in "${candidates[@]}"; do
      [[ -n "$series" ]] || continue
      if ubuntu_php_packages_usable "$series"; then
        PHP_VER="$series"
        PHP_SERVICE="php${PHP_VER}-fpm"
        log "Ubuntu selected PHP ${PHP_VER}; FPM service: ${PHP_SERVICE}"
        return 0
      fi
    done

    if [[ "$attempt" == "0" ]]; then
      log "Ubuntu: enabling third-party Ondrej PHP PPA for the required PHP packages"
      pkg_install software-properties-common
      wait_for_pkg_locks
      add-apt-repository -y ppa:ondrej/php
      wait_for_pkg_locks
      apt-get update
    fi
  done
  die "Ubuntu: no usable stable PHP runtime/extensions were found. Review APT errors above; requested PHP_VER=${requested}."
}

select_ubuntu_php_cli() {
  [[ "${ID:-}" == "ubuntu" ]] || return 0
  [[ -x "/usr/bin/php${PHP_VER}" ]] \
    || die "Ubuntu PHP CLI is missing: /usr/bin/php${PHP_VER}"
  update-alternatives --set php "/usr/bin/php${PHP_VER}"
  hash -r
  local actual_series
  actual_series="$(php -r 'echo PHP_MAJOR_VERSION.".".PHP_MINOR_VERSION;')"
  [[ "$actual_series" == "$PHP_VER" ]] \
    || die "Another php binary is overriding /usr/bin/php in PATH. Expected ${PHP_VER}; got ${actual_series}. Inspect: command -v php"
  log "Ubuntu CLI: PHP $(php -r 'echo PHP_VERSION;'); FPM: ${PHP_SERVICE}"
}

install_composer() {
  log "Installing Composer"
  if command -v composer >/dev/null 2>&1; then
    composer --version || true
    return 0
  fi
  php -r "copy('https://getcomposer.org/installer','/tmp/composer-setup.php');"
  php /tmp/composer-setup.php --install-dir=/usr/local/bin --filename=composer
  rm -f /tmp/composer-setup.php
  composer --version
}

install_node() {
  log "Installing Node.js ${NODE_MAJOR}.x"
  if command -v node >/dev/null 2>&1 && node -v | grep -qE "^v${NODE_MAJOR}\."; then
    node -v
    npm -v || true
    return 0
  fi

  if [[ "$OS_FAMILY" == "debian" ]]; then
    curl -fsSL "https://deb.nodesource.com/setup_${NODE_MAJOR}.x" | bash -
    pkg_install nodejs
  elif [[ "$OS_FAMILY" == "redhat" ]]; then
    curl -fsSL "https://rpm.nodesource.com/setup_${NODE_MAJOR}.x" | bash -
    pkg_install nodejs
  else
    pkg_install nodejs npm
  fi

  node -v || true
  npm -v || true
}

wait_for_mysql() {
  log "Waiting for MySQL/MariaDB to be ready"
  systemctl enable --now "$DB_SERVICE_NAME" || true
  local i=0
  until mysqladmin ping --silent >/dev/null 2>&1; do
    i=$((i+1))
    if [[ $i -gt 120 ]]; then
      systemctl status "$DB_SERVICE_NAME" --no-pager || true
      journalctl -u "$DB_SERVICE_NAME" -n 200 --no-pager || true
      die "MySQL/MariaDB startup failed"
    fi
    sleep 2
  done
}

create_database() {
  log "Creating MySQL database: ${DB_NAME}"
  sudo mysql --protocol=socket -e \
    "CREATE DATABASE IF NOT EXISTS \`${DB_NAME}\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
}

setup_mysql_app_user() {
  log "Creating/updating MySQL user '${DB_USER}'@'localhost' + grants on '${DB_NAME}'"

  sudo mysql --protocol=socket <<SQL
CREATE USER IF NOT EXISTS '${DB_USER}'@'localhost' IDENTIFIED BY '${DB_PASS}';
ALTER USER '${DB_USER}'@'localhost' IDENTIFIED BY '${DB_PASS}';
GRANT ALL PRIVILEGES ON \`${DB_NAME}\`.* TO '${DB_USER}'@'localhost';
FLUSH PRIVILEGES;
SQL
}

db_preflight_test() {
  log "Testing MySQL credentials (TCP preflight)"
  MYSQL_PWD="$DB_PASS" mysql --protocol=tcp -h "$DB_HOST" -P "$DB_PORT" -u "$DB_USER" -e "SELECT 1;" "$DB_NAME" >/dev/null \
    || die "MySQL preflight failed. Wrong creds OR user lacks access to DB '$DB_NAME'."
}

detect_php_fpm_sock() {
  local preferred="/var/run/php/php${PHP_VER}-fpm.sock"
  [[ -S "$preferred" ]] && { echo "$preferred"; return 0; }

  local found
  found="$(ls -1 /var/run/php/php*-fpm.sock /run/php-fpm/www.sock /run/php-fpm/php-fpm.sock /run/php-fpm.sock /var/run/php-fpm.sock 2>/dev/null | head -n 1 || true)"
  [[ -n "$found" ]] || die "No PHP-FPM socket found in standard directories"
  echo "$found"
}

set_env_kv() {
  local file="$1" key="$2" val="$3"
  if grep -qE "^${key}=" "$file"; then
    sed -i "s#^${key}=.*#${key}=${val}#g" "$file"
  else
    echo "${key}=${val}" >> "$file"
  fi
}

configure_arch_php() {
  if [[ "$OS_FAMILY" == "arch" ]]; then
    log "Configuring Arch Linux PHP extensions"
    local ini="/etc/php/php.ini"
    if [[ -f "$ini" ]]; then
      local exts=(pdo_mysql bcmath curl gd intl zip iconv mysqli sockets sqlite3)
      for ext in "${exts[@]}"; do
        sed -i "s/^;extension=${ext}/extension=${ext}/" "$ini" || true
        sed -i "s/^; extension=${ext}/extension=${ext}/" "$ini" || true
      done
    fi
  fi
}

install_php_redis_extensions() {
  log "Installing PHP Redis extensions"
  case "$OS_FAMILY" in
    debian)
      if ! pkg_install "php${PHP_VER}-redis"; then
        pkg_install php-redis
      fi
      ;;
    redhat)
      pkg_install php-pecl-igbinary php-pecl-redis || pkg_install php-igbinary php-pecl-redis || die "Failed to install php Redis packages on Red Hat/Fedora"
      ;;
    arch)
      pkg_install php-igbinary php-redis php-sodium
      ;;
    suse)
      pkg_install php8-redis php8-igbinary || pkg_install php-redis php-igbinary || log "Warning: could not verify SUSE Redis PHP package names automatically"
      ;;
  esac
}

enable_php_redis_extensions() {
  log "Ensuring PHP extensions igbinary + redis are enabled"
  case "$OS_FAMILY" in
    arch)
      mkdir -p /etc/php/conf.d
      if [[ -f /etc/php/conf.d/igbinary.ini ]]; then
        sed -i 's/^;[[:space:]]*extension[[:space:]]*=[[:space:]]*igbinary.*/extension=igbinary.so/' /etc/php/conf.d/igbinary.ini || true
      else
        printf 'extension=igbinary.so\n' >/etc/php/conf.d/igbinary.ini
      fi
      if [[ -f /etc/php/conf.d/redis.ini ]]; then
        sed -i 's/^;[[:space:]]*extension[[:space:]]*=[[:space:]]*redis.*/extension=redis/' /etc/php/conf.d/redis.ini || true
      else
        printf 'extension=redis.so\n' >/etc/php/conf.d/redis.ini
      fi
      rm -f /etc/php/conf.d/20-igbinary.ini /etc/php/conf.d/30-redis.ini 2>/dev/null || true
      ;;
    redhat)
      mkdir -p /etc/php.d
      if [[ -f /etc/php.d/40-igbinary.ini ]]; then
        sed -i 's/^;[[:space:]]*extension[[:space:]]*=[[:space:]]*igbinary.*/extension=igbinary.so/' /etc/php.d/40-igbinary.ini || true
      elif [[ -f /etc/php.d/igbinary.ini ]]; then
        sed -i 's/^;[[:space:]]*extension[[:space:]]*=[[:space:]]*igbinary.*/extension=igbinary.so/' /etc/php.d/igbinary.ini || true
      else
        printf 'extension=igbinary.so
' >/etc/php.d/40-igbinary.ini
      fi
      if [[ -f /etc/php.d/50-redis.ini ]]; then
        sed -i 's/^;[[:space:]]*extension[[:space:]]*=[[:space:]]*redis.*/extension=redis.so/' /etc/php.d/50-redis.ini || true
      elif [[ -f /etc/php.d/redis.ini ]]; then
        sed -i 's/^;[[:space:]]*extension[[:space:]]*=[[:space:]]*redis.*/extension=redis.so/' /etc/php.d/redis.ini || true
      else
        printf 'extension=redis.so
' >/etc/php.d/50-redis.ini
      fi
      ;;
    *)
      :
      ;;
  esac
}

verify_php_redis_extensions() {
  log "Verifying PHP extensions igbinary + redis"
  php -m | grep -qi '^redis$' || die "PHP redis extension is not loaded"
  if php -m | grep -qi '^igbinary$'; then
    :
  else
    log "Warning: igbinary is not loaded; continuing only if redis works without it"
  fi
  php --ri redis >/dev/null 2>&1 || die "PHP redis extension is installed but not functioning"
}

detect_runtime_web_user_group() {
  local app_user=""
  local app_group=""
  local fpm_conf=""

  for f in /etc/php-fpm.d/www.conf /etc/php*/php-fpm.d/www.conf; do
    if [[ -f "$f" ]]; then
      fpm_conf="$f"
      break
    fi
  done

  # Ubuntu keeps each PHP series in its own configuration directory.
  if [[ "${ID:-}" == "ubuntu" && -f "/etc/php/${PHP_VER}/fpm/pool.d/www.conf" ]]; then
    fpm_conf="/etc/php/${PHP_VER}/fpm/pool.d/www.conf"
  fi

  if [[ -n "$fpm_conf" ]]; then
    app_user="$(awk -F= '/^[[:space:]]*user[[:space:]]*=/{gsub(/[[:space:]]+/,"",$2); print $2; exit}' "$fpm_conf" || true)"
    app_group="$(awk -F= '/^[[:space:]]*group[[:space:]]*=/{gsub(/[[:space:]]+/,"",$2); print $2; exit}' "$fpm_conf" || true)"
  fi

  if [[ -z "$app_user" ]]; then
    app_user="$(ps -eo user,comm | awk '$2=="php-fpm" && $1!="root"{print $1; exit}' || true)"
  fi

  if [[ -z "$app_group" && -n "$app_user" ]]; then
    app_group="$(id -gn "$app_user" 2>/dev/null || true)"
  fi

  if [[ -z "$app_user" ]]; then
    if id nginx >/dev/null 2>&1; then
      app_user="nginx"
    elif id apache >/dev/null 2>&1; then
      app_user="apache"
    elif id www-data >/dev/null 2>&1; then
      app_user="www-data"
    elif id http >/dev/null 2>&1; then
      app_user="http"
    else
      die "Could not determine PHP-FPM runtime user"
    fi
  fi

  if [[ -z "$app_group" ]]; then
    app_group="$(id -gn "$app_user" 2>/dev/null || true)"
  fi

  [[ -n "$app_user" ]] || die "Could not determine runtime user"
  [[ -n "$app_group" ]] || die "Could not determine runtime group"

  RUNTIME_WEB_USER="$app_user"
  RUNTIME_WEB_GROUP="$app_group"
  WEB_HOME="$(getent passwd "$RUNTIME_WEB_USER" | cut -d: -f6 || true)"

  if [[ -z "$WEB_HOME" ]]; then
    case "$RUNTIME_WEB_USER" in
      http) WEB_HOME="/srv/http" ;;
      nginx) WEB_HOME="/var/lib/nginx" ;;
      apache) WEB_HOME="/usr/share/httpd" ;;
      www-data) WEB_HOME="/var/www" ;;
      *) WEB_HOME="/tmp/${RUNTIME_WEB_USER}" ;;
    esac
  fi

  log "Detected PHP-FPM runtime user/group: ${RUNTIME_WEB_USER}:${RUNTIME_WEB_GROUP}"
  log "Detected runtime home: ${WEB_HOME}"
}

fix_arch_nginx_main_config() {
  [[ "$OS_FAMILY" == "arch" ]] || return 0

  log "Fixing Arch nginx main config to load conf.d and remove default welcome site"

  cat >/etc/nginx/nginx.conf <<'EOF'
#user http;
worker_processes  1;

#error_log  logs/error.log;
#error_log  logs/error.log  notice;
#error_log  logs/error.log  info;

#pid        logs/nginx.pid;

# Load all installed modules
include modules.d/*.conf;

events {
    worker_connections 1024;
}

http {
    include       mime.types;
    default_type  application/octet-stream;

    #log_format  main  '$remote_addr - $remote_user [$time_local] "$request" '
    #                  '$status $body_bytes_sent "$http_referer" '
    #                  '"$http_user_agent" "$http_x_forwarded_for"';

    #access_log  logs/access.log  main;

    sendfile        on;
    #tcp_nopush     on;

    keepalive_timeout 65;

    #gzip  on;

    include /etc/nginx/conf.d/*.conf;
}
EOF
}

configure_nginx_site() {
  log "Configuring Nginx"

  local nginx_sites_avail="/etc/nginx/sites-available"
  local nginx_sites_en="/etc/nginx/sites-enabled"

  if [[ "$OS_FAMILY" == "redhat" || "$OS_FAMILY" == "arch" || "$OS_FAMILY" == "suse" ]]; then
    nginx_sites_avail="/etc/nginx/conf.d"
    nginx_sites_en="/etc/nginx/conf.d"
  fi

  mkdir -p "$nginx_sites_avail"
  mkdir -p "$nginx_sites_en"

  rm -f "${nginx_sites_en}/default" || true
  rm -f "${nginx_sites_en}/default.conf" || true
  rm -f /etc/nginx/conf.d/default.conf /etc/nginx/conf.d/example_ssl.conf 2>/dev/null || true

  if [[ "$OS_FAMILY" == "arch" ]]; then
    fix_arch_nginx_main_config
  fi

  local php_sock
  php_sock="$(detect_php_fpm_sock)"

  cat >"${nginx_sites_avail}/pfcentral.conf" <<EOF
server {
    listen 80 default_server;
    $(ipv6_ok && echo "listen [::]:80 default_server;")
    server_name ${PFC_SERVER_NAME};
    root ${INSTALL_DIR}/public;

    index index.php index.html;

    location / {
        try_files \$uri \$uri/ /index.php?\$query_string;
    }

    location /app {
        proxy_pass http://127.0.0.1:8080;
        proxy_http_version 1.1;
        proxy_set_header Upgrade \$http_upgrade;
        proxy_set_header Connection "Upgrade";
        proxy_set_header Host \$host;
        proxy_set_header X-Real-IP \$remote_addr;
        proxy_set_header X-Forwarded-For \$proxy_add_x_forwarded_for;
        proxy_set_header X-Forwarded-Proto \$scheme;
    }

    location /apps {
        proxy_pass http://127.0.0.1:8080;
        proxy_http_version 1.1;
        proxy_set_header Upgrade \$http_upgrade;
        proxy_set_header Connection "Upgrade";
        proxy_set_header Host \$host;
        proxy_set_header X-Real-IP \$remote_addr;
        proxy_set_header X-Forwarded-For \$proxy_add_x_forwarded_for;
        proxy_set_header X-Forwarded-Proto \$scheme;
    }

    location ~ \.php$ {
        include fastcgi_params;
        fastcgi_pass unix:${php_sock};
        fastcgi_param SCRIPT_FILENAME \$realpath_root\$fastcgi_script_name;
        fastcgi_param DOCUMENT_ROOT \$realpath_root;
    }

    location ~ /\.(?!well-known).* { deny all; }
}
EOF

  if [[ "$nginx_sites_avail" != "$nginx_sites_en" ]]; then
    ln -sf "${nginx_sites_avail}/pfcentral.conf" "${nginx_sites_en}/pfcentral.conf" || true
  fi

  nginx -t || die "Nginx config test failed"
  systemctl reload nginx || systemctl restart nginx || true
}

run_as_runtime_user() {
  local cmd="$1" v
  # sudo drops the environment: pass on a proxy, if this server uses one.
  local -a pass=()
  for v in http_proxy https_proxy no_proxy HTTP_PROXY HTTPS_PROXY NO_PROXY; do
    [[ -n "${!v:-}" ]] && pass+=("${v}=${!v}")
  done
  sudo -u "${RUNTIME_WEB_USER}" -H env HOME="${WEB_HOME}" npm_config_cache="${WEB_HOME}/.npm" "${pass[@]}" bash -lc "$cmd"
}

install_frontend_clean() {
  log "Fixing npm cache ownership + ensuring clean frontend install"

  mkdir -p "${WEB_HOME}/.npm"
  chown -R "${RUNTIME_WEB_USER}:${RUNTIME_WEB_GROUP}" "${WEB_HOME}" || true
  chown -R "${RUNTIME_WEB_USER}:${RUNTIME_WEB_GROUP}" "${WEB_HOME}/.npm" || true

  rm -rf "${INSTALL_DIR}/node_modules" || true
  chown -R "${RUNTIME_WEB_USER}:${RUNTIME_WEB_GROUP}" "${INSTALL_DIR}" || true

  log "Installing frontend deps + building (as ${RUNTIME_WEB_USER}, HOME=${WEB_HOME})"
  run_as_runtime_user "
    set -Eeuo pipefail
    cd '${INSTALL_DIR}'
    npm cache clean --force || true
    npm cache verify || true
    for attempt in 1 2; do
      if [[ -f package-lock.json ]]; then npm ci --no-audit --no-fund || true; else npm install --no-audit --no-fund || true; fi
      [[ -x node_modules/.bin/vite ]] && break
      echo \"npm did not finish installing the build tools (attempt \$attempt); trying again\"
      rm -rf node_modules
    done
    [[ -x node_modules/.bin/vite ]] || { echo \"npm could not install the build tools: check that this server can reach registry.npmjs.org\"; exit 1; }
    npm run build
  "
}


get_supervisor_main_conf() {
  local f
  for f in /etc/supervisor/supervisord.conf /etc/supervisord.conf; do
    [[ -f "$f" ]] && { echo "$f"; return 0; }
  done
  return 1
}

detect_supervisor_layout() {
  local main_conf=""
  local include_pattern=""
  local include_dir=""
  local include_base=""
  local include_glob=""
  local ext="conf"

  main_conf="$(get_supervisor_main_conf || true)"

  if [[ -n "$main_conf" ]]; then
    include_pattern="$(awk -F= '/^[[:space:]]*files[[:space:]]*=/{print $2}' "$main_conf" | tail -n1 | xargs || true)"
  fi

  if [[ -n "$include_pattern" ]]; then
    include_base="$(dirname "$main_conf")"
    if [[ "$include_pattern" = /* ]]; then
      include_glob="$include_pattern"
    else
      include_glob="${include_base}/${include_pattern}"
    fi

    include_dir="$(dirname "$include_glob")"
    if [[ "$include_glob" == *.ini ]]; then
      ext="ini"
    elif [[ "$include_glob" == *.conf ]]; then
      ext="conf"
    fi
  fi

  if [[ -z "$include_dir" ]]; then
    if [[ -d /etc/supervisor/conf.d ]]; then
      include_dir="/etc/supervisor/conf.d"
      ext="conf"
    elif [[ -d /etc/supervisord.d ]]; then
      include_dir="/etc/supervisord.d"
      ext="ini"
    elif [[ -d /etc/supervisor.d ]]; then
      include_dir="/etc/supervisor.d"
      ext="ini"
    else
      include_dir="/etc/supervisor/conf.d"
      ext="conf"
    fi
  fi

  echo "$include_dir|$ext"
}

ensure_supervisor_service_running() {
  local svc=""

  # Ubuntu's package provides supervisor.service. Avoid a pipefail/grep -q
  # false negative here; leave the original discovery for other OSes alone.
  if [[ "${ID:-}" == "ubuntu" ]]; then
    systemctl enable --now supervisor >&2 \
      || { die "Could not start Ubuntu supervisor.service" >&2; }
    printf '%s\n' supervisor
    return 0
  fi

  if systemctl list-unit-files 2>/dev/null | awk '{print $1}' | grep -qx 'supervisor.service'; then
    svc="supervisor"
  elif systemctl list-unit-files 2>/dev/null | awk '{print $1}' | grep -qx 'supervisord.service'; then
      svc="supervisord"
  else
    svc="supervisord"
  fi

  systemctl enable --now "$svc" || true
  echo "$svc"
}

final_fix_permissions() {
  log "Running embedded final permissions fix"

  [[ -n "${RUNTIME_WEB_USER}" ]] || detect_runtime_web_user_group
  [[ -n "${RUNTIME_WEB_GROUP}" ]] || detect_runtime_web_user_group

  mkdir -p "${INSTALL_DIR}/storage/framework/cache" \
           "${INSTALL_DIR}/storage/framework/sessions" \
           "${INSTALL_DIR}/storage/framework/views" \
           "${INSTALL_DIR}/bootstrap/cache" \
           "${INSTALL_DIR}/public/build" \
           "${INSTALL_DIR}/storage/logs"

  chown -R "${RUNTIME_WEB_USER}:${RUNTIME_WEB_GROUP}" \
    "${INSTALL_DIR}/storage" \
    "${INSTALL_DIR}/bootstrap/cache" \
    "${INSTALL_DIR}/public/build"

  find "${INSTALL_DIR}/storage" -type d -exec chmod 2775 {} \;
  find "${INSTALL_DIR}/storage" -type f -exec chmod 0664 {} \;

  find "${INSTALL_DIR}/bootstrap/cache" -type d -exec chmod 2775 {} \;
  find "${INSTALL_DIR}/bootstrap/cache" -type f -exec chmod 0664 {} \;

  find "${INSTALL_DIR}/public/build" -type d -exec chmod 2775 {} \; 2>/dev/null || true
  find "${INSTALL_DIR}/public/build" -type f -exec chmod 0664 {} \; 2>/dev/null || true

  if command -v getenforce >/dev/null 2>&1; then
    if [[ "$(getenforce || true)" != "Disabled" ]]; then
      log "Applying SELinux contexts"
      if ! command -v semanage >/dev/null 2>&1; then
        if [[ "$OS_FAMILY" == "redhat" ]]; then
          dnf install -y policycoreutils-python-utils || true
        fi
      fi

      if command -v semanage >/dev/null 2>&1; then
        semanage fcontext -a -t httpd_sys_rw_content_t "${INSTALL_DIR}/storage(/.*)?" 2>/dev/null || true
        semanage fcontext -a -t httpd_sys_rw_content_t "${INSTALL_DIR}/bootstrap/cache(/.*)?" 2>/dev/null || true
        semanage fcontext -a -t httpd_sys_rw_content_t "${INSTALL_DIR}/public/build(/.*)?" 2>/dev/null || true
      fi

      restorecon -Rv "${INSTALL_DIR}/storage" "${INSTALL_DIR}/bootstrap/cache" "${INSTALL_DIR}/public/build" 2>/dev/null || true
      setsebool -P httpd_unified 1 2>/dev/null || true
      setsebool -P httpd_can_network_connect 1 2>/dev/null || true
    fi
  fi

  if [[ -f "${INSTALL_DIR}/artisan" ]]; then
    run_as_runtime_user "
      cd '${INSTALL_DIR}'
      php artisan view:clear || true
      php artisan cache:clear || true
      php artisan config:clear || true
      php artisan route:clear || true
      php artisan storage:link || true
    "
  fi

  systemctl restart "${PHP_SERVICE}" || true
  systemctl restart nginx || true
}

provision_cron() {
  log "Provisioning Laravel scheduler cron"

  local cron_line="* * * * * ${RUNTIME_WEB_USER} php ${INSTALL_DIR}/artisan schedule:run >> /dev/null 2>&1"
  local cron_file="/etc/cron.d/pfcentral"
  local cron_dir="/etc/cron.d"

  # Only create if missing (handles Arch clean installs)
  if [[ ! -d "$cron_dir" ]]; then
    mkdir -p "$cron_dir"
    chmod 0755 "$cron_dir"
  fi

  # Idempotent: only write if the artisan schedule:run line is not already present.
  if [[ -f "$cron_file" ]] && grep -qF "artisan schedule:run" "$cron_file"; then
    log "Laravel scheduler cron already present — skipping"
    return 0
  fi

  cat > "$cron_file" << EOF
# PFCentral — Laravel task scheduler
# Drives backend firewall status polling independently of browser activity.
# Do not remove: without this, pfSense status checks only run when a browser has /firewalls open.
${cron_line}
EOF

  chmod 0644 "$cron_file"
  log "Cron installed: $cron_file"
  log "Entry: ${cron_line}"
}


# =============================================================================
#  Pre-checks: nothing on the server is changed here.
# =============================================================================
PRE_FAIL=0; PRE_WARN=0
pre_ok()   { say "    ${OK} $*"; }
pre_warn() { say "    ${WARN} $*"; PRE_WARN=$((PRE_WARN+1)); }
pre_fail() { say "    ${NO} $*"; PRE_FAIL=$((PRE_FAIL+1)); }

port_owner() { # prints the program listening on TCP port $1, empty if free
  local p="$1" out=""
  if command -v ss >/dev/null 2>&1; then
    out="$(ss -H -ltnp "sport = :$p" 2>/dev/null | head -n1 || true)"
    [[ -z "$out" ]] && return 0
    printf '%s' "$out" | sed -nE 's/.*users:\(\("([^"]+)".*/\1/p' | head -n1
    printf '%s' "$out" | grep -q 'users:' || printf 'unknown'
  elif command -v netstat >/dev/null 2>&1; then
    out="$(netstat -ltnp 2>/dev/null | awk -v p=":$p" '$4 ~ p"$" {print $7; exit}' || true)"
    [[ -n "$out" ]] && printf '%s' "${out#*/}"
  fi
  return 0
}

detect_public_ip() {
  PUBLIC_IP="$(curl -4 -fsS -m 8 https://api.ipify.org 2>/dev/null || curl -4 -fsS -m 8 https://ifconfig.me 2>/dev/null || true)"
  [[ "$PUBLIC_IP" =~ ^[0-9]+(\.[0-9]+){3}$ ]] || PUBLIC_IP=""
}

resolve_domain() { { getent ahostsv4 "$1" 2>/dev/null || true; } | awk '{print $1}' | sort -u | tr '\n' ' ' | sed 's/ $//'; }

prechecks() {
  say "  ${C_B}Checking this server${C_0} ${C_D}(nothing is changed yet)${C_0}"

  # Operating system
  local id="" ver="" pretty=""
  if [[ -f /etc/os-release ]]; then
    id="$(. /etc/os-release; echo "${ID:-}")"; ver="$(. /etc/os-release; echo "${VERSION_ID:-}")"
    pretty="$(. /etc/os-release; echo "${PRETTY_NAME:-$id}")"
  fi
  case "$id" in
    ubuntu)
      if [[ "$ver" == "24.04" || "$ver" == "24.10" || "$ver" == 25.* || "$ver" == 26.* ]]; then pre_ok "$pretty (supported)"
      elif [[ "$ver" == "22.04" ]]; then pre_warn "$pretty works, but Ubuntu 24.04 LTS is recommended"
      else pre_fail "$pretty is too old: use Ubuntu 24.04 LTS or newer"; fi ;;
    debian) pre_warn "$pretty should work, but only Ubuntu 24.04 is fully tested" ;;
    fedora) pre_ok "$pretty (supported)" ;;
    rhel|centos|rocky|almalinux|arch|opensuse*|sles) pre_warn "$pretty is experimental: Ubuntu 24.04 LTS is recommended" ;;
    *) pre_fail "Unsupported system (${pretty:-unknown}): use Ubuntu 24.04 LTS" ;;
  esac

  # systemd
  if [[ -d /run/systemd/system ]]; then pre_ok "systemd is running"
  else pre_fail "systemd isn't running (containers without systemd aren't supported)"; fi

  # CPU architecture
  case "$(uname -m)" in
    x86_64|aarch64) pre_ok "CPU: $(uname -m), $(nproc 2>/dev/null || echo '?') cores" ;;
    *) pre_fail "CPU architecture $(uname -m) isn't supported (x86_64 or arm64 needed)" ;;
  esac

  # Memory
  local mem_mb; mem_mb=$(( $(awk '/MemTotal/{print $2}' /proc/meminfo) / 1024 ))
  if   (( mem_mb >= 3800 )); then pre_ok "Memory: ${mem_mb} MB"
  elif (( mem_mb >= 1800 )); then pre_warn "Memory: ${mem_mb} MB works for a few firewalls; 4 GB is recommended"
  else pre_fail "Memory: ${mem_mb} MB is not enough (2 GB minimum, 4 GB recommended)"; fi

  # Disk
  local probe="/var"; [[ -d "$(dirname "$INSTALL_DIR")" ]] && probe="$(dirname "$INSTALL_DIR")"
  local free_gb; free_gb=$(( $(df -Pk "$probe" | awk 'NR==2{print $4}') / 1048576 ))
  if   (( free_gb >= 10 )); then pre_ok "Free disk: ${free_gb} GB"
  elif (( free_gb >= 5 )); then pre_warn "Free disk: ${free_gb} GB (10 GB recommended)"
  else pre_fail "Free disk: ${free_gb} GB is not enough (5 GB minimum)"; fi

  # Internet access to everything the install downloads from
  local host bad=""
  for host in github.com objects.githubusercontent.com getcomposer.org repo.packagist.org registry.npmjs.org; do
    curl -fsS -m 10 -o /dev/null "https://${host}/" 2>/dev/null || curl -sS -m 10 -o /dev/null -w '%{http_code}' "https://${host}/" 2>/dev/null | grep -qE '^[1-5][0-9][0-9]$' || bad+="${host} "
  done
  if [[ -z "$bad" ]]; then pre_ok "Internet: GitHub, Composer and npm are reachable"
  else pre_fail "Can't reach: ${bad}(check DNS, proxy or outbound firewall)"; fi

  # Ports: free, or already used by the program PFCentral installs there.
  local p owner want
  for p in 80 443 3306 6379 8080; do
    owner="$(port_owner "$p")"
    case "$p" in
      80|443) want="nginx" ;; 3306) want="mysqld|mariadbd|mysql|mariadb" ;; 6379) want="redis-server|redis" ;; 8080) want="php" ;;
    esac
    if [[ -z "$owner" ]]; then pre_ok "Port ${p} is free"
    elif [[ "$owner" =~ ^(${want})$ ]]; then
      if [[ "$p" == "80" || "$p" == "443" ]]; then pre_warn "Port ${p} is used by ${owner}: PFCentral will be added to it, and its default site will be replaced"
      else pre_ok "Port ${p} is used by ${owner} (that's what PFCentral needs)"; fi
    else pre_fail "Port ${p} is used by \"${owner}\". PFCentral needs it: stop or move that program (see: sudo ss -ltnp 'sport = :${p}')"; fi
  done

  # Other web servers
  local svc
  for svc in apache2 httpd caddy lighttpd; do
    if systemctl is-active --quiet "$svc" 2>/dev/null; then pre_fail "${svc} is running and would clash with Nginx: stop it (sudo systemctl disable --now ${svc})"; fi
  done

  # Existing installation
  if [[ -f "${INSTALL_DIR}/.env" ]] && grep -q '^APP_INSTALLED=true' "${INSTALL_DIR}/.env" 2>/dev/null && [[ $RESUME -eq 0 ]]; then
    pre_fail "PFCentral is already installed in ${INSTALL_DIR}. Update it from the app (System → Updates) instead"
  elif [[ -d "$INSTALL_DIR" && -n "$(ls -A "$INSTALL_DIR" 2>/dev/null)" && $RESUME -eq 0 ]]; then
    pre_fail "${INSTALL_DIR} isn't empty: move it away or set INSTALL_DIR to another folder"
  else
    pre_ok "Install folder: ${INSTALL_DIR}"
  fi

  # Package manager busy?
  if command -v fuser >/dev/null 2>&1 && fuser /var/lib/dpkg/lock-frontend >/dev/null 2>&1; then
    pre_warn "Another package installation is running; the installer will wait for it"
  fi
}

check_domain() {
  # Sets PFC_HTTPS to no when the domain can't get a certificate.
  [[ -z "$PFC_DOMAIN" ]] && return 0
  local ips; ips="$(resolve_domain "$PFC_DOMAIN")"
  if [[ -z "$ips" ]]; then
    pre_fail "${PFC_DOMAIN} doesn't resolve yet: add a DNS A record pointing to ${PUBLIC_IP:-the public IP of this server}"
    return 0
  fi
  if [[ -n "$PUBLIC_IP" && " $ips " == *" $PUBLIC_IP "* ]]; then
    pre_ok "${PFC_DOMAIN} points to this server (${PUBLIC_IP})"
  elif [[ -n "$PUBLIC_IP" ]]; then
    if [[ "$PFC_HTTPS" == "yes" ]]; then
      pre_fail "${PFC_DOMAIN} points to ${ips}, but this server's public IP is ${PUBLIC_IP}. Fix the DNS A record, or install without HTTPS for now"
    else
      pre_warn "${PFC_DOMAIN} points to ${ips}, not to this server's public IP ${PUBLIC_IP} (fine behind a proxy or NAT)"
    fi
  else
    pre_warn "Couldn't find this server's public IP to compare with ${PFC_DOMAIN} (${ips})"
  fi
}

# =============================================================================
#  Questions
# =============================================================================
valid_domain() { [[ "$1" =~ ^([A-Za-z0-9]([A-Za-z0-9-]{0,61}[A-Za-z0-9])?\.)+[A-Za-z]{2,63}$ ]]; }
valid_email()  { [[ "$1" =~ ^[^@[:space:]]+@[^@[:space:]]+\.[^@[:space:]]+$ ]]; }

questions() {
  say "  ${C_B}A few questions${C_0} ${C_D}(press Enter to accept the suggestion in brackets)${C_0}"
  say ""
  while true; do
    ask "  Domain name for PFCentral, e.g. console.example.com (leave empty to use the IP address)" "$PFC_DOMAIN"
    PFC_DOMAIN="${REPLY,,}"; PFC_DOMAIN="${PFC_DOMAIN#http://}"; PFC_DOMAIN="${PFC_DOMAIN#https://}"; PFC_DOMAIN="${PFC_DOMAIN%%/*}"
    [[ -z "$PFC_DOMAIN" ]] && break
    valid_domain "$PFC_DOMAIN" && break
    say "    ${NO} \"${PFC_DOMAIN}\" isn't a valid domain name."; PFC_DOMAIN=""
    [[ $UNATTENDED -eq 1 ]] && die "PFC_DOMAIN is not a valid domain name"
  done

  if [[ -n "$PFC_DOMAIN" ]]; then
    if [[ -z "$PFC_HTTPS" ]]; then
      if yesno "  Get a free HTTPS certificate from Let's Encrypt for ${PFC_DOMAIN}?" y; then PFC_HTTPS=yes; else PFC_HTTPS=no; fi
    fi
    if [[ "$PFC_HTTPS" == "yes" ]]; then
      while true; do
        ask "  Email for Let's Encrypt expiry notices" "$PFC_EMAIL"; PFC_EMAIL="$REPLY"
        valid_email "$PFC_EMAIL" && break
        say "    ${NO} Please enter a valid email address."
        [[ $UNATTENDED -eq 1 ]] && die "PFC_EMAIL is required for HTTPS"
      done
    fi
  else
    PFC_HTTPS=no
  fi

  ask "  Install folder" "$INSTALL_DIR"; INSTALL_DIR="$REPLY"
  [[ "$INSTALL_DIR" == /* ]] || die "The install folder must be a full path, like /var/www/pfcentral"

  if [[ -z "$DB_PASS" ]]; then
    DB_PASS="$(openssl rand -base64 48 2>/dev/null | tr -dc 'A-Za-z0-9' | head -c 32 || true)"
    [[ ${#DB_PASS} -ge 24 ]] || DB_PASS="$(tr -dc 'A-Za-z0-9' </dev/urandom | head -c 32)"
    say "  ${C_D}A strong database password is generated and saved in ${INSTALL_DIR}/.env.${C_0}"
  fi

  if [[ -z "$PFC_REPORT" ]]; then
    say ""
    say "  If something goes wrong, the installer can send an error report to the PFCentral developers"
    say "  on GitHub, so the problem gets fixed. Passwords, keys, your domain, email and IP addresses"
    say "  are removed from it first."
    if yesno "  Send error reports automatically?" y; then PFC_REPORT=yes; else PFC_REPORT=no; fi
  fi

  PFC_SERVER_NAME="${PFC_DOMAIN:-_}"
}

summary() {
  say ""
  hr
  say "  ${C_B}Ready to install${C_0}"
  say "    Address:        $( [[ -n "$PFC_DOMAIN" ]] && echo "$( [[ "$PFC_HTTPS" == yes ]] && echo https || echo http )://${PFC_DOMAIN}" || echo "http://${PUBLIC_IP:-<the IP of this server>}")"
  say "    Install folder: ${INSTALL_DIR}"
  say "    Database:       ${DB_NAME} (user ${DB_USER})"
  say "    Error reports:  $( [[ "$PFC_REPORT" == yes ]] && echo "sent automatically" || echo "not sent")"
  say "    Log file:       ${LOG_FILE}"
  hr
}

# =============================================================================
#  Steps. Each step_x does the work; verify_x proves it worked.
# =============================================================================
step_packages() {
  log "Updating package manager"
  case "$OS_FAMILY" in
    debian) wait_for_pkg_locks; apt-get update -y ;;
    redhat) dnf makecache ;;
    arch) pacman -Sy ;;
    suse) zypper refresh ;;
  esac

  log "Installing base packages"
  case "$OS_FAMILY" in
    debian) pkg_install ca-certificates curl gnupg git unzip lsb-release apt-transport-https openssl psmisc ;;
    redhat) pkg_install ca-certificates curl gnupg2 git unzip openssl psmisc ;;
    arch)   pkg_install ca-certificates curl gnupg git unzip lsb-release wget openssl psmisc ;;
    suse)   pkg_install ca-certificates curl gnupg2 git unzip lsb-release openssl psmisc ;;
  esac

  log "Installing Nginx + Database Server + Supervisor + Redis + Certbot"
  case "$OS_FAMILY" in
    debian) pkg_install nginx mysql-server supervisor certbot python3-certbot-nginx redis-server cron ;;
    redhat) pkg_install nginx mariadb-server supervisor certbot python3-certbot-nginx redis cronie ;;
    arch)
      pkg_install nginx mariadb supervisor certbot certbot-nginx redis cronie
      [[ -d "/var/lib/mysql/mysql" ]] || mariadb-install-db --user=mysql --basedir=/usr --datadir=/var/lib/mysql || true ;;
    suse)   pkg_install nginx mariadb supervisor certbot python3-certbot-nginx redis cron ;;
  esac

  log "Enabling and starting Redis"
  systemctl enable --now redis-server 2>/dev/null || systemctl enable --now redis 2>/dev/null || true

  [[ "${ID:-}" == "ubuntu" ]] && prepare_ubuntu_php

  log "Installing PHP + extensions"
  case "$OS_FAMILY" in
    debian)
      pkg_install php${PHP_VER}-cli php${PHP_VER}-fpm php${PHP_VER}-mysql php${PHP_VER}-mbstring php${PHP_VER}-xml \
        php${PHP_VER}-curl php${PHP_VER}-zip php${PHP_VER}-gd php${PHP_VER}-bcmath php${PHP_VER}-intl ;;
    redhat) pkg_install php-cli php-fpm php-mysqlnd php-mbstring php-xml php-curl php-zip php-gd php-bcmath php-intl || true ;;
    arch)   pkg_install php php-fpm php-gd php-intl php-sqlite php-sodium; configure_arch_php ;;
    suse)   pkg_install php8-cli php8-fpm php8-mysql php8-mbstring php8-curl php8-zip php8-gd php8-bcmath php8-intl || true ;;
  esac

  if [[ "${ID:-}" == "ubuntu" ]]; then
    select_ubuntu_php_cli
    pkg_install "php${PHP_VER}-redis"
    systemctl daemon-reload
  fi
  install_php_redis_extensions
  enable_php_redis_extensions
  install_composer
  install_node

  log "Enabling services"
  nginx_ipv4_only
  systemctl enable nginx
  systemctl restart nginx
  systemctl enable --now "${PHP_SERVICE}"
  systemctl restart "${PHP_SERVICE}"
  systemctl enable --now cron 2>/dev/null || systemctl enable --now crond 2>/dev/null || systemctl enable --now cronie 2>/dev/null || true
  verify_php_redis_extensions
  detect_runtime_web_user_group
}
verify_packages() {
  local c m
  for c in nginx php composer node npm mysql git supervisorctl redis-cli certbot openssl; do
    command -v "$c" >/dev/null 2>&1 || check_fail "the program \"$c\" is missing after installing"
  done
  for m in pdo_mysql mbstring xml curl zip gd bcmath intl redis; do
    php -m | grep -qi "^${m}$" || check_fail "the PHP extension \"$m\" isn't loaded"
  done
  php -r 'exit(version_compare(PHP_VERSION, "8.2.0", ">=") ? 0 : 1);' || check_fail "PHP $(php -r 'echo PHP_VERSION;') is too old (8.2 or newer needed)"
  systemctl is-active --quiet nginx || check_fail "Nginx isn't running"
  systemctl is-active --quiet "$PHP_SERVICE" || check_fail "PHP-FPM (${PHP_SERVICE}) isn't running"
  [[ "$(redis-cli ping 2>/dev/null)" == "PONG" ]] || check_fail "Redis isn't answering"
  [[ -n "$RUNTIME_WEB_USER" ]] || check_fail "couldn't find the web server's user"
  echo "PHP $(php -r 'echo PHP_VERSION;'), Node $(node -v), Composer $(composer --version 2>/dev/null | awk '{print $3}')"
}

step_database() {
  wait_for_mysql
  create_database
  setup_mysql_app_user
}
verify_database() {
  MYSQL_PWD="$DB_PASS" mysql --protocol=tcp -h "$DB_HOST" -P "$DB_PORT" -u "$DB_USER" -e "SELECT 1;" "$DB_NAME" >/dev/null \
    || check_fail "can't sign in to the database \"${DB_NAME}\" as \"${DB_USER}\""
}

step_download() {
  if [[ -d "${INSTALL_DIR}/.git" ]]; then
    log "Updating the earlier download in ${INSTALL_DIR}"
    git -C "$INSTALL_DIR" fetch --depth 1 origin main
    git -C "$INSTALL_DIR" reset --hard FETCH_HEAD
  else
    log "Downloading PFCentral to ${INSTALL_DIR}"
    rm -rf "$INSTALL_DIR"
    mkdir -p "$(dirname "$INSTALL_DIR")"
    git clone --depth 1 "$REPO_URL" "$INSTALL_DIR"
  fi
  # Keep a full history so the in-app updater can work with it.
  git -C "$INSTALL_DIR" fetch --unshallow 2>/dev/null || true
  APP_VERSION="$(tr -d '[:space:]' <"${INSTALL_DIR}/VERSION" 2>/dev/null || echo unknown)"
}
verify_download() {
  [[ -f "${INSTALL_DIR}/artisan" && -d "${INSTALL_DIR}/public" && -f "${INSTALL_DIR}/composer.lock" ]] \
    || check_fail "the download in ${INSTALL_DIR} is incomplete"
  echo "PFCentral ${APP_VERSION}"
}

app_url() {
  if [[ -n "$PFC_DOMAIN" ]]; then
    if [[ "$PFC_HTTPS" == "yes" ]] && step_done https; then echo "https://${PFC_DOMAIN}"; else echo "http://${PFC_DOMAIN}"; fi
  else
    echo "http://${PUBLIC_IP:-localhost}"
  fi
}

step_configure() {
  cd "$INSTALL_DIR"
  [[ -f .env ]] || cp .env.example .env
  sed -i '/^DB_DATABASE=database\/database\.sqlite$/d; /^DB_CONNECTION=sqlite$/d' .env

  set_env_kv .env "APP_NAME" '"PFCentral"'
  set_env_kv .env "APP_URL" "$(app_url)"
  set_env_kv .env "APP_ENV" "production"
  set_env_kv .env "APP_DEBUG" "false"
  set_env_kv .env "GITHUB_REPOSITORY" "$REPO_SLUG"

  set_env_kv .env "DB_CONNECTION" "mysql"
  set_env_kv .env "DB_HOST" "$DB_HOST"
  set_env_kv .env "DB_PORT" "$DB_PORT"
  set_env_kv .env "DB_DATABASE" "$DB_NAME"
  set_env_kv .env "DB_USERNAME" "$DB_USER"
  set_env_kv .env "DB_PASSWORD" "$DB_PASS"

  if ! grep -qE '^REVERB_APP_KEY=.+' .env; then
    set_env_kv .env "REVERB_APP_KEY" "$(openssl rand -hex 16)"
    set_env_kv .env "REVERB_APP_SECRET" "$(openssl rand -hex 16)"
  fi
  set_env_kv .env "BROADCAST_CONNECTION" "reverb"
  set_env_kv .env "REVERB_APP_ID" "pfcentral"
  set_env_kv .env "REVERB_HOST" "localhost"
  set_env_kv .env "REVERB_PORT" "8080"
  set_env_kv .env "REVERB_SCHEME" "http"
  set_env_kv .env "VITE_REVERB_APP_KEY" '\${REVERB_APP_KEY}'
  set_env_kv .env "VITE_REVERB_HOST" '\${REVERB_HOST}'
  set_env_kv .env "VITE_REVERB_PORT" '\${REVERB_PORT}'
  set_env_kv .env "VITE_REVERB_SCHEME" '\${REVERB_SCHEME}'

  set_env_kv .env "CACHE_DRIVER" "redis"
  set_env_kv .env "CACHE_STORE" "redis"
  set_env_kv .env "SESSION_DRIVER" "redis"
  set_env_kv .env "QUEUE_CONNECTION" "redis"
  set_env_kv .env "REDIS_CLIENT" "phpredis"
  set_env_kv .env "REDIS_HOST" "127.0.0.1"
  set_env_kv .env "REDIS_PASSWORD" "null"
  set_env_kv .env "REDIS_PORT" "6379"

  chown "${RUNTIME_WEB_USER}:${RUNTIME_WEB_GROUP}" .env
  chmod 0640 .env
  mkdir -p "${WEB_HOME}"
  chown -R "${RUNTIME_WEB_USER}:${RUNTIME_WEB_GROUP}" "${WEB_HOME}" "$INSTALL_DIR" || true
}
verify_configure() {
  local k
  for k in APP_URL DB_DATABASE DB_USERNAME DB_PASSWORD REVERB_APP_KEY QUEUE_CONNECTION; do
    grep -qE "^${k}=.+" "${INSTALL_DIR}/.env" || check_fail "${k} is missing from .env"
  done
}

step_composer() {
  cd "$INSTALL_DIR"
  log "Checking PHP and its extensions against composer.lock"
  run_as_runtime_user "cd '${INSTALL_DIR}' && composer --no-interaction --no-plugins --no-scripts check-platform-reqs --lock --no-dev" \
    || die "This PHP ${PHP_VER} or its extensions don't meet PFCentral's requirements (see the Composer messages above)"
  log "Installing PHP libraries"
  run_as_runtime_user "cd '${INSTALL_DIR}' && COMPOSER_NO_INTERACTION=1 composer install --no-dev --prefer-dist --no-progress --optimize-autoloader"
}
verify_composer() {
  [[ -f "${INSTALL_DIR}/vendor/autoload.php" ]] || check_fail "the PHP libraries weren't installed (vendor/autoload.php missing)"
  run_as_runtime_user "cd '${INSTALL_DIR}' && php artisan --version" || check_fail "PFCentral's command line doesn't start"
}

step_appsetup() {
  cd "$INSTALL_DIR"
  grep -qE '^APP_KEY=base64:.+' .env || run_as_runtime_user "cd '${INSTALL_DIR}' && php artisan key:generate --force"
  run_as_runtime_user "cd '${INSTALL_DIR}' && php artisan migrate --force"
  run_as_runtime_user "cd '${INSTALL_DIR}' && php artisan storage:link" || true
  run_as_runtime_user "cd '${INSTALL_DIR}' && php artisan package:discover --ansi"
  set_env_kv .env "APP_INSTALLED" "true"
}
verify_appsetup() {
  grep -qE '^APP_KEY=base64:.+' "${INSTALL_DIR}/.env" || check_fail "the app key wasn't created"
  run_as_runtime_user "cd '${INSTALL_DIR}' && php artisan migrate:status --no-ansi" >/dev/null || check_fail "the database tables aren't readable"
  run_as_runtime_user "cd '${INSTALL_DIR}' && php artisan migrate:status --no-ansi" | grep -q Pending && check_fail "some database tables weren't created"
  return 0
}

step_frontend() { install_frontend_clean; }
verify_frontend() {
  [[ -f "${INSTALL_DIR}/public/build/manifest.json" ]] || check_fail "the web pages weren't built (public/build/manifest.json missing)"
}

step_privileges() {
  final_fix_permissions

  log "Installing the safe SSL helpers (the app may only run these, never certbot or nginx directly)"
  APP_USER="$RUNTIME_WEB_USER" bash "${INSTALL_DIR}/scripts/setup-ssl-permissions.sh"

  log "Allowing the app to restart its own background services"
  # Only restarts. Copying files into Supervisor's or PHP's config folders is not
  # allowed: a web user who could do that could run any command as root.
  local f=/etc/sudoers.d/pfcentral-tuning tmp
  tmp="$(mktemp)"
  {
    echo "# PFCentral: restart its own services only."
    echo "${RUNTIME_WEB_USER} ALL=(root) NOPASSWD: /usr/bin/supervisorctl restart pfcentral-worker\\:*, /usr/bin/supervisorctl restart pfcentral-reverb\\:*, /usr/bin/supervisorctl restart pfcentral-reverb"
    echo "${RUNTIME_WEB_USER} ALL=(root) NOPASSWD: /usr/bin/systemctl restart ${PHP_SERVICE}"
  } >"$tmp"
  visudo -cf "$tmp" >/dev/null || { rm -f "$tmp"; die "The sudo rules for restarting services are invalid"; }
  install -o root -g root -m 0440 "$tmp" "$f"; rm -f "$tmp"
}
verify_privileges() {
  visudo -c >/dev/null 2>&1 || check_fail "sudo's settings are invalid"
  [[ -x /usr/local/bin/pfcentral-request-cert ]] || check_fail "the SSL helper scripts weren't installed"
  run_as_runtime_user "test -w '${INSTALL_DIR}/storage/logs' && test -w '${INSTALL_DIR}/bootstrap/cache'" \
    || check_fail "the web user can't write to storage/ or bootstrap/cache/"
}

ipv6_ok() { [[ -f /proc/net/if_inet6 ]] && [[ "$(cat /proc/sys/net/ipv6/conf/all/disable_ipv6 2>/dev/null || echo 0)" != "1" ]]; }
nginx_ipv4_only() {
  # Nginx won't start if a site listens on IPv6 while IPv6 is switched off.
  ipv6_ok && return 0
  log "IPv6 is switched off on this server: Nginx will listen on IPv4 only"
  local f
  for f in /etc/nginx/sites-enabled/* /etc/nginx/conf.d/*.conf; do
    [[ -f "$f" ]] && sed -i -E 's/^([[:space:]]*)(listen[[:space:]]+\[::\])/\1# \2/' "$(readlink -f "$f")"
  done
  return 0
}

open_firewall_ports() {
  if command -v ufw >/dev/null 2>&1 && ufw status 2>/dev/null | grep -q "Status: active"; then
    log "Opening ports 80 and 443 in ufw"
    ufw allow 80/tcp; ufw allow 443/tcp
  fi
  if command -v firewall-cmd >/dev/null 2>&1 && firewall-cmd --state >/dev/null 2>&1; then
    log "Opening HTTP and HTTPS in firewalld"
    firewall-cmd --permanent --add-service=http; firewall-cmd --permanent --add-service=https; firewall-cmd --reload
  fi
  return 0
}
step_webserver() {
  configure_nginx_site
  if ! ipv6_ok; then nginx_ipv4_only; nginx -t; systemctl reload nginx; fi
  open_firewall_ports
}
local_http_code() { curl -s -o /dev/null -m 20 -w '%{http_code}' -H "Host: ${PFC_DOMAIN:-localhost}" "http://127.0.0.1/${1:-}" || true; }
verify_webserver() {
  nginx -t >/dev/null 2>&1 || check_fail "Nginx's configuration has an error (run: sudo nginx -t)"
  local code; code="$(local_http_code)"
  [[ "$code" =~ ^(200|302)$ ]] || check_fail "PFCentral's home page answered HTTP ${code:-nothing} instead of 200 or 302 (see ${INSTALL_DIR}/storage/logs/laravel.log)"
  echo "home page: HTTP ${code}"
}

step_services() {
  local supervisor_service supv_layout supv_conf_dir supv_ext
  supervisor_service="$(ensure_supervisor_service_running)"
  supv_layout="$(detect_supervisor_layout)"
  supv_conf_dir="${supv_layout%%|*}"; supv_ext="${supv_layout##*|}"
  mkdir -p "$supv_conf_dir"
  rm -f "${supv_conf_dir}"/pfcentral-worker.{ini,conf} "${supv_conf_dir}"/pfcentral-reverb.{ini,conf}

  local cpu_cores num_workers
  cpu_cores="$(nproc 2>/dev/null || echo 4)"
  num_workers=$(( cpu_cores * 2 )); (( num_workers < 4 )) && num_workers=4; (( num_workers > 16 )) && num_workers=16
  log "Configuring ${num_workers} queue workers (${cpu_cores} CPU cores)"

  cat >"${supv_conf_dir}/pfcentral-worker.${supv_ext}" <<EOF
[program:pfcentral-worker]
process_name=%(program_name)s_%(process_num)02d
command=php ${INSTALL_DIR}/artisan queue:work redis --sleep=3 --tries=3 --max-time=3600
autostart=true
autorestart=true
user=${RUNTIME_WEB_USER}
numprocs=${num_workers}
redirect_stderr=true
stdout_logfile=${INSTALL_DIR}/storage/logs/worker.log
stopwaitsecs=3600
EOF
  cat >"${supv_conf_dir}/pfcentral-reverb.${supv_ext}" <<EOF
[program:pfcentral-reverb]
command=php ${INSTALL_DIR}/artisan reverb:start --host=127.0.0.1 --port=8080
directory=${INSTALL_DIR}
autostart=true
autorestart=true
user=${RUNTIME_WEB_USER}
numprocs=1
redirect_stderr=true
stdout_logfile=${INSTALL_DIR}/storage/logs/reverb.log
stopasgroup=true
killasgroup=true
EOF

  # PHP-FPM pool sized to the memory available (~50 MB per worker, 10 to 40).
  local fpm_pool_conf="" f
  for f in "/etc/php/${PHP_VER}/fpm/pool.d/www.conf" /etc/php-fpm.d/www.conf /etc/php/php-fpm.d/www.conf; do
    [[ -f "$f" ]] && { fpm_pool_conf="$f"; break; }
  done
  if [[ -n "$fpm_pool_conf" ]]; then
    local ram_mb max_children
    ram_mb=$(( $(awk '/MemTotal/{print $2}' /proc/meminfo) / 1024 ))
    max_children=$(( ram_mb / 50 )); (( max_children < 10 )) && max_children=10; (( max_children > 40 )) && max_children=40
    sed -i -e "s/^pm = .*/pm = dynamic/" \
      -e "s/^pm\.max_children = .*/pm.max_children = ${max_children}/" \
      -e "s/^pm\.start_servers = .*/pm.start_servers = $(( max_children / 4 ))/" \
      -e "s/^pm\.min_spare_servers = .*/pm.min_spare_servers = $(( max_children / 4 ))/" \
      -e "s/^pm\.max_spare_servers = .*/pm.max_spare_servers = $(( max_children / 2 ))/" \
      -e "s/^;pm\.max_requests = .*/pm.max_requests = 500/" "$fpm_pool_conf"
    systemctl restart "${PHP_SERVICE}"
  fi

  systemctl enable --now "${supervisor_service}"
  supervisorctl reread
  supervisorctl update
  supervisorctl start 'pfcentral-worker:*' || true
  supervisorctl start pfcentral-reverb || true
  provision_cron
  sleep 5
}
verify_services() {
  local st; st="$(supervisorctl status 2>/dev/null || true)"
  echo "$st"
  echo "$st" | grep -E '^pfcentral-worker' | grep -q RUNNING || check_fail "the background workers aren't running (see ${INSTALL_DIR}/storage/logs/worker.log)"
  echo "$st" | grep -E '^pfcentral-reverb' | grep -q RUNNING || check_fail "the live-updates service isn't running (see ${INSTALL_DIR}/storage/logs/reverb.log)"
  echo "$st" | grep -E '^pfcentral' | grep -qE 'FATAL|BACKOFF|EXITED' && check_fail "a background service keeps stopping (run: sudo supervisorctl status)"
  [[ -f /etc/cron.d/pfcentral ]] || check_fail "the scheduler (cron) wasn't set up"
  return 0
}

step_https() {
  if [[ "$PFC_HTTPS" != "yes" || -z "$PFC_DOMAIN" ]]; then
    echo "HTTPS not requested; skipping."
    return 0
  fi
  log "Requesting a Let's Encrypt certificate for ${PFC_DOMAIN}"
  if ! certbot --nginx --non-interactive --agree-tos --no-eff-email -m "$PFC_EMAIL" -d "$PFC_DOMAIN" --redirect; then
    die "Let's Encrypt couldn't issue a certificate for ${PFC_DOMAIN}. Check that the domain points to this server and that port 80 is open to the internet, then resume. Or install without HTTPS: PFC_HTTPS=no sudo -E bash ${STATE_DIR}/install_pfcentral.sh --resume"
  fi
  set_env_kv "${INSTALL_DIR}/.env" "APP_URL" "https://${PFC_DOMAIN}"
  run_as_runtime_user "cd '${INSTALL_DIR}' && php artisan config:clear" || true
  systemctl reload nginx
}
verify_https() {
  [[ "$PFC_HTTPS" != "yes" || -z "$PFC_DOMAIN" ]] && return 0
  local code
  code="$(curl -s -o /dev/null -m 20 -w '%{http_code}' --resolve "${PFC_DOMAIN}:443:127.0.0.1" "https://${PFC_DOMAIN}/" || true)"
  [[ "$code" =~ ^(200|302)$ ]] || check_fail "https://${PFC_DOMAIN} answered HTTP ${code:-nothing} with its new certificate"
  systemctl list-timers 2>/dev/null | grep -q certbot || [[ -f /etc/cron.d/certbot ]] || echo "note: certbot's renewal timer wasn't found"
}

HEALTH_FAIL=0
health_line() { if eval "$2" >/dev/null 2>&1; then say "    ${OK} $1"; else say "    ${NO} $1"; HEALTH_FAIL=$((HEALTH_FAIL+1)); fi; }
step_health() { :; }
verify_health() {
  stop_ticker
  say ""
  HEALTH_FAIL=0
  health_line "Nginx is running"                      "systemctl is-active --quiet nginx"
  health_line "PHP-FPM is running"                    "systemctl is-active --quiet '$PHP_SERVICE'"
  health_line "Database is running"                   "MYSQL_PWD='$DB_PASS' mysql --protocol=tcp -h '$DB_HOST' -P '$DB_PORT' -u '$DB_USER' -e 'SELECT 1' '$DB_NAME'"
  health_line "Redis is answering"                    "[[ \$(redis-cli ping) == PONG ]]"
  health_line "Background workers are running"        "supervisorctl status | grep -E '^pfcentral-worker' | grep -q RUNNING"
  health_line "Live updates (Reverb) are running"     "supervisorctl status pfcentral-reverb | grep -q RUNNING"
  health_line "Scheduler is set up"                   "test -f /etc/cron.d/pfcentral"
  health_line "Database tables are up to date"        "! run_as_runtime_user \"cd '$INSTALL_DIR' && php artisan migrate:status --no-ansi\" | grep -q Pending"
  health_line "Web pages are built"                   "test -f '$INSTALL_DIR/public/build/manifest.json'"
  health_line "Home page answers (HTTP)"              "[[ \$(local_http_code) =~ ^(200|302)\$ ]]"
  if [[ "$PFC_HTTPS" == "yes" && -n "$PFC_DOMAIN" ]]; then
    health_line "HTTPS works for ${PFC_DOMAIN}"       "[[ \$(curl -s -o /dev/null -m 20 -w '%{http_code}' --resolve '${PFC_DOMAIN}:443:127.0.0.1' 'https://${PFC_DOMAIN}/') =~ ^(200|302)\$ ]]"
  fi
  (( HEALTH_FAIL == 0 )) || check_fail "${HEALTH_FAIL} final check(s) failed (marked ✖ above)"
}

# =============================================================================
#  Main
# =============================================================================
keep_copy() {
  # Keep a copy of this installer so --resume works even when it was piped from curl.
  mkdir -p "$STATE_DIR"; chmod 700 "$STATE_DIR"
  local self="${BASH_SOURCE[0]:-}"
  if [[ -n "$self" && -f "$self" ]]; then
    [[ "$(readlink -f "$self")" == "$(readlink -f "${STATE_DIR}/install_pfcentral.sh" 2>/dev/null)" ]] || cp -f "$self" "${STATE_DIR}/install_pfcentral.sh"
  else
    curl -fsSL -m 30 "https://raw.githubusercontent.com/${REPO_SLUG}/main/install_pfcentral.sh" -o "${STATE_DIR}/install_pfcentral.sh" || true
  fi
  chmod 700 "${STATE_DIR}/install_pfcentral.sh" 2>/dev/null || true
}

main() {
  [[ "${EUID}" -eq 0 ]] || { echo "Please run as root: sudo bash $0"; EXPECTED_EXIT=1; exit 1; }
  if [[ $UNATTENDED -eq 0 ]]; then
    [[ -r /dev/tty && -w /dev/tty ]] || { echo "This installer asks questions, so it needs a terminal. Or run it with --unattended and PFC_* settings (see --help)."; EXPECTED_EXIT=1; exit 1; }
    exec </dev/tty
  fi

  touch "$LOG_FILE"; chmod 600 "$LOG_FILE"
  if [[ $VERBOSE -eq 1 ]]; then exec > >(tee -a "$LOG_FILE") 2>&1; else exec >>"$LOG_FILE" 2>&1; fi
  echo; echo "======== PFCentral installer ${INSTALLER_VERSION} started $(date -Is) ($*) ========"

  banner
  keep_copy

  if [[ $RESUME -eq 0 && -f "$STATE_FILE" && "$MODE" == "install" ]]; then
    local failed; failed="$(state_get failed_step)"
    say "  An earlier installation stopped${failed:+ at \"${STEP_TITLE[$failed]:-$failed}\"}."
    if yesno "  Continue where it stopped?" y; then RESUME=1
    elif yesno "  Start again from the beginning? (the earlier answers and progress are forgotten)" n; then
      rm -f "$STATE_FILE"
    else exit 0; fi
    say ""
  fi
  [[ $RESUME -eq 1 ]] && load_answers

  os_detect
  [[ -n "$PHP_SERVICE" ]] || PHP_SERVICE="php${PHP_VER}-fpm"
  # A resumed install keeps the PHP version chosen the first time.
  [[ $RESUME -eq 1 && -n "$(state_get PHP_SERVICE)" ]] && PHP_SERVICE="$(state_get PHP_SERVICE)" && PHP_VER="$(state_get PHP_VER)"
  detect_public_ip

  if [[ $RESUME -eq 0 || -z "$(state_get PFC_REPORT)" ]]; then
    questions
  else
    PFC_SERVER_NAME="${PFC_DOMAIN:-_}"
    say "  Continuing the installation for ${C_B}$(app_url)${C_0}"
  fi
  say ""

  PRE_FAIL=0; PRE_WARN=0
  prechecks
  check_domain
  say ""
  if (( PRE_FAIL > 0 )); then
    say "  ${NO} ${C_R}${C_B}${PRE_FAIL} check(s) failed${C_0}, so nothing was installed. Fix the items marked ${NO} and run the installer again."
    echo "pre-checks failed: ${PRE_FAIL}"
    EXPECTED_EXIT=1; exit 1
  fi
  say "  ${OK} All checks passed$( (( PRE_WARN > 0 )) && echo " (${PRE_WARN} warning(s) above)")."
  if [[ "$MODE" == "check" ]]; then say ""; exit 0; fi

  summary
  if [[ $RESUME -eq 0 ]]; then
    yesno "  Start the installation?" y || { say "  Nothing was changed."; exit 0; }
  fi
  save_answers
  say ""
  say "  ${C_B}Installing${C_0} ${C_D}(about 5 to 15 minutes; details in ${LOG_FILE})${C_0}"
  say ""

  local i=0 total=${#STEP_IDS[@]} id
  for id in "${STEP_IDS[@]}"; do
    i=$((i+1))
    # Steps that only need the settings of earlier steps re-detect them on resume.
    if [[ -z "$RUNTIME_WEB_USER" && "$id" != "packages" ]]; then detect_runtime_web_user_group; fi
    run_step "$id" "$i" "$total"
  done
  CURRENT_STEP=""

  state_set "installed" "$(date -Is)"
  state_set "failed_step" ""
  local url; url="$(app_url)"
  say ""
  hr
  say "  ${OK} ${C_G}${C_B}PFCentral ${APP_VERSION} is installed and working.${C_0}"
  say ""
  say "  Next: open ${C_B}${url}/setup${C_0} to create your administrator account."
  [[ "$PFC_HTTPS" != "yes" && -n "$PFC_DOMAIN" ]] && say "  For HTTPS later: System Settings → Site Configuration in PFCentral."
  say "  Each pfSense firewall needs the pfRest package and HTTPS: see docs/INSTALL.md."
  say ""
  say "  Install folder: ${INSTALL_DIR}"
  say "  Log file:       ${LOG_FILE}"
  hr
  say ""
}

main "$@"
