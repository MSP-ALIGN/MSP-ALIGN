#!/bin/bash
# MSP-ALIGN container start-up (1.44). Writes the server config from environment variables, prepares the data
# folders, waits for the database, applies migrations, creates the first admin, then runs the web server with the
# scheduler (the jobs systemd runs on a dedicated server) and the backup agent next to it.
#
#   web        (default) Apache + scheduler + agent
#   align ...  run a command-line task as the web user, e.g.  docker compose exec app align user:reset-password --email=...
#   anything else runs as given (e.g. bash)
#
# Security assumptions: runs as root, started by tini (PID 1). The environment comes from the operator (.env,
# compose.yaml) and is trusted to be theirs, but every value that ends up in config.php goes through php_str, so a
# quote or newline in it can't add PHP code. Secrets (database password, app key, admin password) are never
# printed; the one exception is a generated temporary admin password, shown once like install.sh does. Inside the
# data volume root only makes things as www-data (see "folders"), because the web user can change anything there.
# The web server runs as www-data; the scheduler runs as root, and runs the app's jobs as www-data.
set -euo pipefail

APP=/opt/msp-align
CONF_DIR=/etc/msp-align
CONF=$CONF_DIR/config.php
DATA=/var/lib/msp-align
AGENT=/var/lib/msp-align-agent
RUN=/run/msp-align

# A line for the container log. Never pass it a secret.
log()  { printf '[msp-align] %s\n' "$*"; }
# Stops the start-up with a message on stderr (Docker restarts the container per its restart policy).
die()  { printf '[msp-align] ERROR: %s\n' "$*" >&2; exit 1; }
# VAR or the contents of VAR_FILE (Docker secrets), else the default. Prints the value on stdout, so only call it
# inside $(...). The file is read as root: the operator chose its path. CR and LF are dropped (a file from an editor).
env_or_file() {
  local name=$1 def=${2:-} file_var="${1}_FILE"
  if [[ -n "${!file_var:-}" ]]; then
    [[ -r "${!file_var}" ]] || die "$file_var points to ${!file_var}, which can't be read."
    tr -d '\r\n' <"${!file_var}"
  else
    printf '%s' "${!name:-$def}"
  fi
}
# A PHP single-quoted string literal for any value (var_export escapes ' and \), for writing config.php.
# The value is passed as an argument, never put into the PHP code that runs here.
php_str() { php -r 'echo var_export($argv[1], true);' -- "$1"; }
# Reads an on/off setting: 1/true/yes/on or 0/false/no/off/empty (any case); prints 1 or 0. Anything else stops the
# start-up, so a typo can't quietly leave a switch such as ALIGN_STAGING off (2.2.1).
env_bool() {
  local name=$1 v=${!1:-}
  case "${v,,}" in
    1|true|yes|on) echo 1 ;;
    0|false|no|off|'') echo 0 ;;
    *) die "$name must be 1 or 0 (it is '$v')." ;;
  esac
}

case "${1:-web}" in
  web) ;;
  align) shift; exec runuser -u www-data -- php "$APP/bin/align" "$@" ;;
  *) exec "$@" ;;
esac

# ------------------------------------------------------------------ settings --
ALIGN_URL=${ALIGN_URL:-}
[[ -n "$ALIGN_URL" ]] || die "Set ALIGN_URL to the address people will use, e.g. https://align.example.com"
[[ "$ALIGN_URL" =~ ^https?://([A-Za-z0-9]([A-Za-z0-9.-]*[A-Za-z0-9])?)(:[0-9]{1,5})?/?$ ]] || die "ALIGN_URL '$ALIGN_URL' is not a valid address (https://host or https://host:port, no path)."
FQDN=${BASH_REMATCH[1]}
ALIGN_URL=${ALIGN_URL%/}
TZ_NAME=${ALIGN_TZ:-${TZ:-UTC}}
[[ "$TZ_NAME" =~ ^[A-Za-z0-9_+/-]+$ && -e "/usr/share/zoneinfo/$TZ_NAME" ]] || die "Time zone '$TZ_NAME' is not valid."
DB_HOST=${ALIGN_DB_HOST:-db}
DB_NAME=${ALIGN_DB_NAME:-msp_align}
DB_USER=${ALIGN_DB_USER:-msp_align}
DB_PASS=$(env_or_file ALIGN_DB_PASSWORD)
[[ -n "$DB_PASS" ]] || die "Set ALIGN_DB_PASSWORD (or ALIGN_DB_PASSWORD_FILE) to the database password."
[[ "$DB_NAME" =~ ^[A-Za-z0-9_]{1,64}$ ]] || die "ALIGN_DB_NAME may only use letters, numbers and _."
[[ "$DB_HOST" =~ ^[A-Za-z0-9._:-]+$ ]] || die "ALIGN_DB_HOST '$DB_HOST' is not valid."
BRANCH=${ALIGN_UPDATE_BRANCH:-main}
[[ "$BRANCH" =~ ^[A-Za-z0-9][A-Za-z0-9._/-]{0,59}$ ]] || die "ALIGN_UPDATE_BRANCH '$BRANCH' is not valid."
# Reverse proxies allowed to pass on the visitor's address and https: addresses or ranges (10.0.0.0/8), comma or space separated
PROXIES=()
TP=${ALIGN_TRUSTED_PROXIES:-}
for p in ${TP//,/ }; do
  [[ "$p" =~ ^[0-9A-Fa-f:.]+(/([0-9]{1,3}))?$ ]] || die "ALIGN_TRUSTED_PROXIES: '$p' is not an IP address or range."
  bits=${BASH_REMATCH[2]:-}
  # base 10: a leading zero ("/09") would otherwise be read as octal, fail the test and let a /9 IPv6 range through
  [[ -n "$bits" ]] && bits=$((10#$bits))
  # a very wide range would let almost anyone fake their address: at least /8 (IPv4) or /16 (IPv6)
  if [[ -n "$bits" ]] && { [[ "$p" == *:* && $bits -lt 16 ]] || [[ "$p" != *:* && $bits -lt 8 ]]; }; then
    die "ALIGN_TRUSTED_PROXIES: '$p' is too wide. List your proxy's own address (or a narrow range)."
  fi
  PROXIES+=("$p")
done
# Test server (docs/TEST-SERVER.md): read strictly, since a test server on a copy of production that silently runs
# in normal mode would email real clients and write to their PSA
STAGING=$(env_bool ALIGN_STAGING)
STAGING_MAIL_TO=${ALIGN_STAGING_MAIL_TO:-}
if [[ $STAGING == 1 && ! "$STAGING_MAIL_TO" =~ ^[^@[:space:]]+@[^@[:space:]]+$ ]]; then
  log "Test server: ALIGN_STAGING_MAIL_TO isn't an email address, so no email will be sent at all."
fi

ln -sf "/usr/share/zoneinfo/$TZ_NAME" /etc/localtime && echo "$TZ_NAME" >/etc/timezone
export TZ=$TZ_NAME

# ------------------------------------------------------------------- folders --
install -d -m 750 -o root -g www-data "$CONF_DIR"
install -d -m 750 -o www-data -g www-data "$DATA"
# Inside the data folder only the web user makes or changes things: as root, a folder it had swapped for a
# symlink would hand the symlink's target to www-data (1.45)
# chown -h never follows a symlink, so a folder restored or copied in as root is handed back to www-data safely
# Only a folder not already owned by www-data, and only where the kernel stops www-data hard-linking other users'
# files (fs.protected_hardlinks=1; a container shares its host's setting): with 0, a folder swapped for a hard link
# to a root file would hand that file to www-data (2.2.1, as install.sh)
HARDLINKS_SAFE=0; [[ "$(cat /proc/sys/fs/protected_hardlinks 2>/dev/null)" == 1 ]] && HARDLINKS_SAFE=1
for d in sessions uploads downloads restore imports; do
  p="$DATA/$d"
  [[ -e "$p" && ! -L "$p" && "$(stat -c %U "$p" 2>/dev/null)" != www-data ]] || continue
  [[ $HARDLINKS_SAFE == 1 ]] || die "$p isn't owned by www-data, and the host lets www-data hard-link other users' files (fs.protected_hardlinks=0), so it is left alone. Check what it is, then fix its owner."
  chown -h www-data:www-data "$p" 2>/dev/null || true
done
runuser -u www-data -- install -d -m 750 "$DATA/uploads" "$DATA/downloads" "$DATA/restore"
runuser -u www-data -- install -d -m 700 "$DATA/sessions"
install -d -m 750 -o root -g www-data "$AGENT" "$AGENT/jobs" "$AGENT/safety"
install -d -m 700 -o root -g root "$AGENT/work"
install -d -m 755 -o root -g root "$RUN"
install -d -m 770 -o root -g www-data "$RUN/requests"
install -d -m 700 -o root -g root "$RUN/keys"
# /run survives a container restart (unlike a server's): drop queued requests, one-time keys and DB login files
find "$RUN/requests" "$RUN/keys" -mindepth 1 -delete 2>/dev/null || true
rm -f "$RUN"/db-*.cnf "$RUN/scheduler.json" "$RUN/scheduler-running.json"
# A job that was running when the container stopped can't finish now: mark it failed and say what to do.
# Root may write these files: the jobs folder is root's own (750 root:www-data), so the web user can't plant them.
for f in "$AGENT"/jobs/*.json; do
  [[ -f "$f" ]] || continue
  php -r '$f = $argv[1]; $j = json_decode((string) file_get_contents($f), true);
    if (!is_array($j) || !in_array($j["state"] ?? "", ["running", "queued"], true)) exit(0);
    $restore = ($j["action"] ?? "") === "restore";
    $j["state"] = "failed"; $j["finished_at"] = date("c");
    $j["message"] = "Stopped when the container stopped." . ($restore ? " The data may be partly restored: restore the backup again (a safety copy of the data from before the restore is on this page)." : " Start it again.");
    file_put_contents($f, json_encode($j, JSON_PRETTY_PRINT)); fwrite(STDERR, "[msp-align] WARNING: the " . ($j["action"] ?? "") . " job " . basename($f, ".json") . " was cut off when the container stopped. " . $j["message"] . "\n");' "$f" || true
done
rm -f "$AGENT/maintenance.json"   # nothing is running any more

# The encryption key for saved passwords, API keys and 2FA secrets: from ALIGN_APP_KEY, else made once and kept in the config volume
APP_KEY=$(env_or_file ALIGN_APP_KEY)
export ALIGN_APP_KEY_FROM_ENV=0
[[ -n "$APP_KEY" ]] && ALIGN_APP_KEY_FROM_ENV=1   # the agent can't replace it on a restore (see scripts/agent.php)
if [[ -z "$APP_KEY" ]]; then
  if [[ ! -s "$CONF_DIR/app-key" ]]; then
    ( umask 077; printf 'base64:%s\n' "$(head -c 32 /dev/urandom | base64 -w0)" >"$CONF_DIR/app-key" )
    log "Created the encryption key in $CONF_DIR/app-key. Keep the config volume (and a copy of that file) safe."
  fi
  APP_KEY=$(tr -d '\r\n' <"$CONF_DIR/app-key")
fi
[[ "$APP_KEY" =~ ^base64:[A-Za-z0-9+/]{43}=$ ]] || die "The encryption key must be base64: followed by 32 bytes in base64 (44 characters)."

# Backup key: backups are encrypted to the public half; the private half is shown once and should be moved off the server
if [[ ! -s "$CONF_DIR/backup-recipient.txt" ]]; then
  # A name only: age-keygen creates the file itself with O_EXCL and mode 600, so a file or symlink planted at that
  # name makes it fail (and the start-up stop) rather than write the key through it
  tmp=$(mktemp -u)
  age-keygen -o "$tmp" 2>/dev/null
  age-keygen -y "$tmp" >"$CONF_DIR/backup-recipient.txt"
  install -m 600 -o root -g root "$tmp" "$CONF_DIR/backup-key.txt"
  rm -f "$tmp"
  log "Created the backup encryption key. Copy the private key somewhere safe, then delete it from the server:"
  log "  docker compose exec app cat /etc/msp-align/backup-key.txt"
  log "  docker compose exec app rm /etc/msp-align/backup-key.txt"
fi
chown root:www-data "$CONF_DIR/backup-recipient.txt" && chmod 640 "$CONF_DIR/backup-recipient.txt"

# config.php: written as a new file (640 root:www-data, umask 027 so it is never readable by others, even for a
# moment) and moved into place. Every value from the environment goes through php_str; the fixed paths are ours.
proxies_php="["
for p in "${PROXIES[@]}"; do proxies_php+="$(php_str "$p"), "; done
proxies_php="${proxies_php%, }]"
( umask 027
  cat >"$CONF.tmp" <<PHP
<?php
// MSP-ALIGN server config - written by the container on every start from its environment variables.
// Edit the .env file (or compose.yaml) and restart instead of changing this file.
return [
    'db' => [
        'host' => $(php_str "$DB_HOST"),
        'name' => $(php_str "$DB_NAME"),
        'user' => $(php_str "$DB_USER"),
        'pass' => $(php_str "$DB_PASS"),
    ],
    'app_key' => $(php_str "$APP_KEY"),
    'base_url' => $(php_str "$ALIGN_URL"),
    'timezone' => $(php_str "$TZ_NAME"),
    'tls_mode' => 'proxy',
    'fqdn' => $(php_str "$FQDN"),
    'trusted_proxies' => $proxies_php,
    'session_path' => '$DATA/sessions',
    'upload_path' => '$DATA/uploads',
    'data_dir' => '$DATA',
    'run_dir' => '$RUN',
    'agent_dir' => '$AGENT',
    'php_cli' => '/usr/bin/php',
    'debug' => false,
    'update_branch' => $(php_str "$BRANCH"),
    'install_type' => 'docker',
    'staging' => $( [[ $STAGING == 1 ]] && echo true || echo false ),
    'staging_mail_to' => $(php_str "$STAGING_MAIL_TO"),
];
PHP
)
chown root:www-data "$CONF.tmp" && chmod 640 "$CONF.tmp" && mv -f "$CONF.tmp" "$CONF"
PHPV=$(php -r 'echo PHP_MAJOR_VERSION.".".PHP_MINOR_VERSION;')
for sapi in apache2 cli; do printf 'date.timezone = %s\n' "$TZ_NAME" >"/etc/php/$PHPV/$sapi/conf.d/98-msp-align-tz.ini"; done

# ------------------------------------------------------------------ database --
log "Waiting for the database at $DB_HOST"
for i in $(seq 1 90); do
  if runuser -u www-data -- php -r 'require "/opt/msp-align/src/bootstrap.php"; Align\DB::pdo()->query("SELECT 1");' >/dev/null 2>&1; then
    break
  fi
  [[ $i == 90 ]] && die "Could not connect to the database at $DB_HOST after 3 minutes. Check ALIGN_DB_* and that the db container is running."
  sleep 2
done
log "Applying database migrations"
runuser -u www-data -- php "$APP/bin/align" migrate

if [[ "$(runuser -u www-data -- php -r 'require "/opt/msp-align/src/bootstrap.php"; echo Align\DB::value("SELECT COUNT(*) FROM users");')" == "0" ]]; then
  ADMIN_EMAIL=${ALIGN_ADMIN_EMAIL:-}
  if [[ -z "$ADMIN_EMAIL" ]]; then
    log "No users yet: set ALIGN_ADMIN_EMAIL and restart to create the first admin."
  else
    ADMIN_PASS=$(env_or_file ALIGN_ADMIN_PASSWORD)
    shown=0
    if [[ -z "$ADMIN_PASS" ]]; then ADMIN_PASS=$(head -c 30 /dev/urandom | base64 -w0 | tr -dc 'A-Za-z0-9' | head -c 20); shown=1; fi
    printf '%s\n' "$ADMIN_PASS" | runuser -u www-data -- php "$APP/bin/align" user:create --email="$ADMIN_EMAIL" \
      --name="${ALIGN_ADMIN_NAME:-Administrator}" --role=admin --password-stdin >/dev/null
    log "Created the first admin: $ADMIN_EMAIL"
    [[ $shown == 1 ]] && log "  Temporary password: $ADMIN_PASS   (sign in at $ALIGN_URL and change it under Account)"
  fi
fi

# ----------------------------------------------------------------------- run --
# Secrets are in config.php now; keep them out of the web server's and jobs' environment
unset ALIGN_DB_PASSWORD ALIGN_DB_PASSWORD_FILE ALIGN_ADMIN_PASSWORD ALIGN_ADMIN_PASSWORD_FILE ALIGN_APP_KEY ALIGN_APP_KEY_FILE ADMIN_PASS DB_PASS APP_KEY
log "MSP-ALIGN $(cat "$APP/VERSION") is starting at $ALIGN_URL"
php "$APP/docker/scheduler.php" &
SCHED=$!
STOPPING=0
# Stops the scheduler and Apache gracefully and waits for them (docker stop, or one of them exiting). Root only.
stop_all() {
  kill -TERM "$SCHED" 2>/dev/null || true          # the scheduler lets running jobs (a backup, a restore) finish first
  apache2ctl -k graceful-stop 2>/dev/null || true   # in-flight requests get GracefulShutdownTimeout (docker/apache.conf)
  wait "$SCHED" 2>/dev/null || true
  wait 2>/dev/null || true
}
trap 'STOPPING=1; stop_all; exit 0' TERM INT
set +u; source /etc/apache2/envvars; set -u
rm -f "${APACHE_PID_FILE:-/var/run/apache2/apache2.pid}"
apache2 -DFOREGROUND &
code=0
wait -n || code=$?
[[ $STOPPING == 1 ]] && exit 0
log "A process stopped (exit $code); stopping the container so Docker can restart it."
stop_all
exit "$code"
