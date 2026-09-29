#!/usr/bin/env bash
# MSP-ALIGN. Copyright (C) 2026 Mountaineer IT Inc. and MSP-ALIGN contributors. SPDX-License-Identifier: AGPL-3.0-or-later (see LICENSE)
# =============================================================================
#  MSP-ALIGN - installer / upgrader for Debian 13 (trixie)
#
#  Fresh install, run on a new Debian 13 VM:
#    curl -fsSL https://raw.githubusercontent.com/MSP-ALIGN/MSP-ALIGN/main/install.sh | sudo -E bash
#  (from a private fork: export GH_TOKEN and ALIGN_REPO=owner/name, and add
#   -H "Authorization: Bearer $GH_TOKEN" to curl)
#
#  Upgrade an existing install:
#    sudo msp-align-update      (the older name, mountaineer-align-update, still works)
#
#  Unattended install: set any of these env vars to skip the matching prompt
#    GH_TOKEN  ALIGN_FQDN  ALIGN_TLS (selfsigned|letsencrypt|proxy)  ALIGN_LE_EMAIL
#    ALIGN_PROXY_IP  ALIGN_ADMIN_EMAIL  ALIGN_ADMIN_NAME  ALIGN_TZ
#    ALIGN_REPO (owner/name)  ALIGN_BRANCH  ALIGN_FORCE=1 (skip OS check)
#    ALIGN_FIREWALL=0 (don't manage ufw)  ALIGN_DB_ENCRYPT=0 (skip MariaDB encryption at rest)
# =============================================================================
set -Eeuo pipefail
umask 022

REPO="${ALIGN_REPO:-MSP-ALIGN/MSP-ALIGN}"
OLD_REPO=MountaineerIT/mountaineer-align   # where the project lived before 1.30.1
APP_DIR=/opt/msp-align
CONF_DIR=/etc/msp-align
CONF_FILE="$CONF_DIR/config.php"
# Before 1.35 everything on the server was named mountaineer-align (see scripts/move-install.sh)
OLD_APP_DIR=/opt/mountaineer-align
OLD_CONF_FILE=/etc/mountaineer-align/config.php
[[ -r "$CONF_FILE" || ! -r "$OLD_CONF_FILE" ]] || CONF_FILE_NOW=$OLD_CONF_FILE
# Updates come from ALIGN_BRANCH, else the install's own setting ('update_branch' in config.php), else main
CONF_BRANCH=$( [[ -r "${CONF_FILE_NOW:-$CONF_FILE}" ]] && command -v php >/dev/null && php -r '$c = @include $argv[1]; echo is_array($c) ? (string) ($c["update_branch"] ?? "") : "";' "${CONF_FILE_NOW:-$CONF_FILE}" 2>/dev/null || true)
BRANCH="${ALIGN_BRANCH:-${CONF_BRANCH:-main}}"
[[ "$BRANCH" =~ ^[A-Za-z0-9][A-Za-z0-9._/-]{0,59}$ && "$BRANCH" != *..* ]] || BRANCH=main
TOKEN_FILE="$CONF_DIR/github-token"
DATA_DIR=/var/lib/msp-align
BACKUP_DIR=/var/backups/mountaineer-align   # old nightly backups (before 1.14); no longer written, name kept
AGENT_DIR=/var/lib/msp-align-agent
DB_NAME=msp_align   # new installs; an existing install keeps its database name (read from config.php below)
DB_USER=align
SITE=msp-align
PRIVKEY_FILE=/root/msp-align-backup-key.txt

MODE=install
[[ "${1:-}" == "--upgrade" ]] && MODE=upgrade

# ---------------------------------------------------------------- helpers ----
c_ok=$'\e[32m'; c_warn=$'\e[33m'; c_err=$'\e[31m'; c_dim=$'\e[2m'; c_b=$'\e[1m'; c_0=$'\e[0m'
log()  { printf '%s==>%s %s\n' "$c_ok" "$c_0" "$*"; }
warn() { printf '%s!!%s  %s\n' "$c_warn" "$c_0" "$*" >&2; }
die()  { printf '%sERROR:%s %s\n' "$c_err" "$c_0" "$*" >&2; exit 1; }
trap 'die "Install stopped at line $LINENO (command: $BASH_COMMAND)"' ERR

have_tty() { [[ -r /dev/tty ]] && (: </dev/tty) 2>/dev/null; }

# ask VAR "Prompt" "default" [secret]
ask() {
  local __var=$1 prompt=$2 def=${3:-} secret=${4:-} reply=
  if [[ -n "${!__var:-}" ]]; then return; fi
  if ! have_tty; then
    [[ -n "$def" ]] || die "$__var is required (no terminal available to prompt)."
    printf -v "$__var" '%s' "$def"; return
  fi
  if [[ -n "$secret" ]]; then
    read -rsp "$prompt: " reply </dev/tty; echo >/dev/tty
  else
    read -rp "$prompt${def:+ [$def]}: " reply </dev/tty
  fi
  printf -v "$__var" '%s' "${reply:-$def}"
}

rand() { openssl rand -base64 64 | tr -dc 'A-Za-z0-9' | cut -c1-"${1:-32}"; }

as_www() { runuser -u www-data -- "$@"; }

php_conf_get() { # php_conf_get "db.pass"
  php -r '$c = require $argv[1]; foreach (explode(".", $argv[2]) as $k) { $c = $c[$k] ?? ""; } echo is_array($c) ? implode(",", $c) : $c;' "$CONF_FILE" "$1"
}

# --------------------------------------------------------------- preflight --
[[ $EUID -eq 0 ]] || die "Run as root (use sudo -E so GH_TOKEN is passed through)."
. /etc/os-release
if [[ "${VERSION_ID:-}" != "13" && "${ALIGN_FORCE:-}" != "1" ]]; then
  die "This installer targets Debian 13 (found: ${PRETTY_NAME:-unknown}). Set ALIGN_FORCE=1 to try anyway."
fi

if [[ -f "$CONF_FILE" || -f "$OLD_CONF_FILE" ]] && [[ "$MODE" == "install" ]]; then
  log "Existing install found - running as an upgrade."
  MODE=upgrade
fi

# 1.35: an install still (or partly) in the mountaineer-align folders moves to msp-align first; the old names
# keep working as links. The mover is safe to run again, so it runs until nothing is left to move. It comes
# with the code; run through curl | bash on an old server, it's taken from the branch being installed.
MOVER=""
for m in "$APP_DIR/scripts/move-install.sh" "$(dirname "$(readlink -f "${BASH_SOURCE[0]:-/dev/null}")")/scripts/move-install.sh"; do
  [[ -f "$m" ]] && { MOVER=$m; break; }
done
OLD_LEFT=0
for d in "$OLD_APP_DIR" /etc/mountaineer-align /var/lib/mountaineer-align /var/lib/mountaineer-align-agent /run/mountaineer-align; do
  [[ -d "$d" && ! -L "$d" ]] && OLD_LEFT=1
done
[[ -e /etc/apache2/sites-available/mountaineer-align.conf ]] && OLD_LEFT=1
if [[ -z "$MOVER" && $OLD_LEFT == 1 ]]; then
  GIT_AT=$APP_DIR; [[ -d "$GIT_AT/.git" ]] || GIT_AT=$OLD_APP_DIR
  MOVER=$(mktemp); MOVER_TMP=$MOVER
  git -C "$GIT_AT" fetch -q origin "$BRANCH" && git -C "$GIT_AT" show "origin/$BRANCH:scripts/move-install.sh" >"$MOVER" \
    || die "Could not get scripts/move-install.sh from $(git -C "$GIT_AT" remote get-url origin 2>/dev/null) ($BRANCH)."
fi
if [[ -n "$MOVER" ]] && bash "$MOVER" --pending; then
  log "Moving to the msp-align folders"
  bash "$MOVER" || die "Moving to the msp-align folders stopped (see above). Nothing was lost: fix what it says and run the update again."
  # The web app now drops its requests in /run/msp-align: watch there from now on (the running agent is left alone)
  if [[ -f /etc/systemd/system/mountaineer-align-agent.path && -f "$APP_DIR/deploy/systemd/msp-align-agent.path" ]]; then
    install -m 644 "$APP_DIR/deploy/systemd/msp-align-agent.path" "$APP_DIR/deploy/systemd/msp-align-agent.service" /etc/systemd/system/
    systemctl daemon-reload
    systemctl disable -q --now mountaineer-align-agent.path 2>/dev/null || true
    rm -f /etc/systemd/system/mountaineer-align-agent.path
    systemctl enable -q --now msp-align-agent.path || warn "Could not start msp-align-agent.path (the installer tries again below)."
  fi
fi
[[ -n "${MOVER_TMP:-}" ]] && rm -f "$MOVER_TMP"

unset CONF_FILE_NOW
if [[ -r "$CONF_FILE" ]] && command -v php >/dev/null; then
  DB_NAME=$(php -r '$c = require $argv[1]; echo $c["db"]["name"] ?? "";' "$CONF_FILE" 2>/dev/null || true)
  DB_NAME=${DB_NAME:-mountaineer_align}
fi

echo
printf '%s  MSP-ALIGN %s%s\n' "$c_b" "$MODE" "$c_0"
printf '%s  repo %s (%s) -> %s%s\n\n' "$c_dim" "$REPO" "$BRANCH" "$APP_DIR" "$c_0"

# ------------------------------------------------------------------ inputs --
if [[ "$MODE" == "upgrade" ]]; then
  command -v php >/dev/null || die "PHP not found - is this really an existing install?"
  ALIGN_FQDN=$(php_conf_get fqdn)
  ALIGN_TLS=$(php_conf_get tls_mode)
  ALIGN_PROXY_IP=$(php_conf_get trusted_proxies)
else
  have_tty && ask GH_TOKEN "GitHub read-only token for $REPO (blank if the repo is public)" "" secret   # optional: unattended installs of a public repo need none
  ask ALIGN_FQDN "Hostname users will browse to" "$(hostname -f 2>/dev/null || hostname)"
  echo "TLS options: selfsigned = certificate generated here (internal use)"
  echo "             letsencrypt = public DNS name reachable on port 80"
  echo "             proxy = plain HTTP behind a reverse proxy/WAF (e.g. BunkerWeb) that terminates TLS"
  ask ALIGN_TLS "TLS mode" "selfsigned"
  case "$ALIGN_TLS" in selfsigned|letsencrypt|proxy) ;; *) die "TLS mode must be selfsigned, letsencrypt or proxy";; esac
  [[ "$ALIGN_TLS" == "letsencrypt" ]] && ask ALIGN_LE_EMAIL "Email for Let's Encrypt notices" ""
  [[ "$ALIGN_TLS" == "proxy" ]] && ask ALIGN_PROXY_IP "IP address of the reverse proxy" ""
  ask ALIGN_ADMIN_EMAIL "Admin user email" ""
  ask ALIGN_ADMIN_NAME "Admin user name" "Administrator"
  ask ALIGN_TZ "Time zone" "$(timedatectl show -p Timezone --value 2>/dev/null || echo UTC)"
  [[ "$ALIGN_FQDN" =~ ^[A-Za-z0-9]([A-Za-z0-9.-]*[A-Za-z0-9])?$ ]] || die "Hostname '$ALIGN_FQDN' is not valid."
  [[ "$ALIGN_TZ" =~ ^[A-Za-z0-9_+/-]+$ && -e "/usr/share/zoneinfo/$ALIGN_TZ" ]] || die "Time zone '$ALIGN_TZ' is not valid."
  [[ -z "${ALIGN_PROXY_IP:-}" || "$ALIGN_PROXY_IP" =~ ^[0-9A-Fa-f:.]+$ ]] || die "Proxy IP '$ALIGN_PROXY_IP' is not valid."
  [[ "$ALIGN_ADMIN_EMAIL" =~ ^[^[:space:]\'\"@]+@[^[:space:]\'\"@]+$ ]] || die "Admin email looks invalid."
  [[ "$ALIGN_TLS" != "proxy" || -n "${ALIGN_PROXY_IP:-}" ]] || die "Proxy IP is required for proxy mode."
  [[ "$ALIGN_TLS" != "letsencrypt" || "${ALIGN_LE_EMAIL:-}" == *@* ]] || die "Let's Encrypt email is required."
fi

# ---------------------------------------------------------------- packages --
log "Installing packages"
export DEBIAN_FRONTEND=noninteractive
apt-get update -qq
PKGS=(apache2 libapache2-mod-php php-cli php-mysql php-curl php-mbstring php-xml php-intl php-gd
      mariadb-server git ca-certificates curl openssl unattended-upgrades age fail2ban ufw)
[[ "${ALIGN_TLS:-}" == "letsencrypt" ]] && PKGS+=(certbot python3-certbot-apache)
apt-get install -y -qq "${PKGS[@]}" >/dev/null
PHPV=$(php -r 'echo PHP_MAJOR_VERSION.".".PHP_MINOR_VERSION;')
# OPcache: compiled PHP stays in memory (a large speed-up for every page)
if ! php -r 'exit(function_exists("opcache_get_status") ? 0 : 1);' && apt-cache show "php$PHPV-opcache" >/dev/null 2>&1; then
  apt-get install -y -qq "php$PHPV-opcache" >/dev/null || warn "Could not install php$PHPV-opcache"
fi
php -r 'exit(function_exists("sodium_crypto_secretbox") ? 0 : 1);' || die "PHP sodium extension missing."

# Automatic security updates
cat >/etc/apt/apt.conf.d/20auto-upgrades <<'EOF'
APT::Periodic::Update-Package-Lists "1";
APT::Periodic::Unattended-Upgrade "1";
EOF
# Accurate clocks matter for audit timestamps and two-factor codes
timedatectl set-ntp true 2>/dev/null || true

# ------------------------------------------------------------- directories --
install -d -m 750 -o root -g www-data "$CONF_DIR"
install -d -m 750 -o www-data -g www-data "$DATA_DIR"
install -d -m 700 -o www-data -g www-data "$DATA_DIR/sessions"
install -d -m 750 -o www-data -g www-data "$DATA_DIR/uploads"
# Updates & backups: the web app queues requests in /run (RAM); the root agent does the work.
# Backups are built for download and deleted once downloaded - nothing is kept on the server.
install -d -m 750 -o www-data -g www-data "$DATA_DIR/downloads" "$DATA_DIR/restore"
install -d -m 750 -o root -g www-data "$AGENT_DIR" "$AGENT_DIR/jobs" "$AGENT_DIR/safety"
install -d -m 700 -o root -g root "$AGENT_DIR/work"
{
  echo "# Managed by the MSP-ALIGN installer"
  echo "d /run/msp-align 0755 root root -"
  echo "d /run/msp-align/requests 0770 root www-data -"
  echo "d /run/msp-align/keys 0700 root root -"
  # an install moved from the old names keeps /run/mountaineer-align working after a reboot too
  if [[ -L "$OLD_APP_DIR" ]]; then echo "L /run/mountaineer-align - - - - /run/msp-align"; fi
} >/etc/tmpfiles.d/msp-align.conf
rm -f /etc/tmpfiles.d/mountaineer-align.conf
systemd-tmpfiles --create /etc/tmpfiles.d/msp-align.conf

# ----------------------------------------------------- backup encryption key --
# Downloaded backups are encrypted to an age public key. The private key is shown once and must be
# stored offline (password manager / safe); only the public key stays on this server.
BACKUP_PRIV_SHOWN=""
if [[ ! -s "$CONF_DIR/backup-recipient.txt" ]]; then
  log "Creating backup encryption key"
  KEYTMP=$(mktemp)
  rm -f "$KEYTMP"
  age-keygen -o "$KEYTMP" 2>/dev/null
  age-keygen -y "$KEYTMP" >"$CONF_DIR/backup-recipient.txt"
  chmod 640 "$CONF_DIR/backup-recipient.txt"
  install -m 600 "$KEYTMP" "$PRIVKEY_FILE"
  rm -f "$KEYTMP"
  BACKUP_PRIV_SHOWN=1
  # Encrypt backups made before encryption was turned on
  for f in "$BACKUP_DIR"/db-*.sql.gz "$BACKUP_DIR"/config-*.php "$BACKUP_DIR"/uploads-*.tar.gz; do
    [[ -f "$f" ]] || continue
    age -R "$CONF_DIR/backup-recipient.txt" -o "$f.age" "$f" && { shred -u "$f" 2>/dev/null || rm -f "$f"; }
  done
fi

if [[ -n "${GH_TOKEN:-}" ]]; then
  umask 077; printf '%s' "$GH_TOKEN" >"$TOKEN_FILE"; umask 022
  chmod 600 "$TOKEN_FILE"
fi

# -------------------------------------------------------------------- code --
# 1.30.1: the project moved to github.com/MSP-ALIGN/MSP-ALIGN. An install still pulling from the old
# repository switches to the new one, if the new one answers; otherwise it keeps the old one and warns.
# Installs whose origin is already some other repository (a fork) are left alone; an old-repo install run
# with ALIGN_REPO=owner/name moves to that repository.
move_origin() {
  local cur new
  cur=$(git -C "$APP_DIR" remote get-url origin 2>/dev/null || true)
  [[ "${cur,,}" =~ ^https://github\.com/${OLD_REPO,,}(\.git)?/?$ ]] || return 0
  [[ "${REPO,,}" != "${OLD_REPO,,}" ]] || return 0
  new="https://github.com/$REPO.git"
  if timeout 60 git -C "$APP_DIR" ls-remote -q "$new" "$BRANCH" >/dev/null 2>&1; then
    git -C "$APP_DIR" remote set-url origin "$new"
    log "Updates now come from github.com/$REPO (the project's new home)"
  else
    warn "Could not reach github.com/$REPO; still updating from github.com/$OLD_REPO for now."
  fi
}
CRED_HELPER="!f() { test \"\$1\" = get || exit 0; test -s $TOKEN_FILE || exit 0; echo username=x-access-token; echo password=\$(cat $TOKEN_FILE); }; f"
if [[ -d "$APP_DIR/.git" ]]; then
  log "Updating code"
  git -C "$APP_DIR" config credential.helper "$CRED_HELPER"
  move_origin
  git -C "$APP_DIR" fetch -q origin "$BRANCH"
  git -C "$APP_DIR" reset -q --hard "origin/$BRANCH"
else
  log "Downloading code from github.com/$REPO"
  rm -rf "$APP_DIR"
  git -c credential.helper="$CRED_HELPER" clone -q --branch "$BRANCH" "https://github.com/$REPO.git" "$APP_DIR" \
    || die "git clone failed. Check the token has Contents: Read on $REPO."
  git -C "$APP_DIR" config credential.helper "$CRED_HELPER"
fi
chown -R root:root "$APP_DIR"
# Readable by everyone (the web server runs as www-data), writable by root only - whatever umask
# the code was checked out with
chmod -R u+rwX,go+rX,go-w "$APP_DIR"
chmod 755 "$APP_DIR/bin/align" "$APP_DIR/scripts/"*.sh "$APP_DIR/scripts/agent.php"
VERSION=$(cat "$APP_DIR/VERSION")

# ---------------------------------------------------------------- database --
systemctl enable -q --now mariadb
if [[ ! -f "$CONF_FILE" ]]; then
  log "Creating database"
  DB_PASS=$(rand 32)
  APP_KEY=$(head -c 32 /dev/urandom | base64)
  mariadb <<SQL
CREATE DATABASE IF NOT EXISTS \`$DB_NAME\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER IF NOT EXISTS '$DB_USER'@'localhost' IDENTIFIED BY '$DB_PASS';
ALTER USER '$DB_USER'@'localhost' IDENTIFIED BY '$DB_PASS';
GRANT ALL PRIVILEGES ON \`$DB_NAME\`.* TO '$DB_USER'@'localhost';
FLUSH PRIVILEGES;
SQL
  PROXIES="[]"
  [[ "$ALIGN_TLS" == "proxy" ]] && PROXIES="['$ALIGN_PROXY_IP']"
  umask 027
  cat >"$CONF_FILE" <<PHP
<?php
// MSP-ALIGN server config - generated by install.sh on $(date -I)
// KEEP A COPY OF app_key: without it, saved API keys and 2FA secrets cannot be decrypted.
return [
    'db' => [
        'host' => 'localhost',
        'name' => '$DB_NAME',
        'user' => '$DB_USER',
        'pass' => '$DB_PASS',
    ],
    'app_key' => 'base64:$APP_KEY',
    'base_url' => 'https://$ALIGN_FQDN',
    'timezone' => '$ALIGN_TZ',
    'tls_mode' => '$ALIGN_TLS',
    'fqdn' => '$ALIGN_FQDN',
    'trusted_proxies' => $PROXIES,
    'session_path' => '$DATA_DIR/sessions',
    'upload_path' => '$DATA_DIR/uploads',
    'php_cli' => '/usr/bin/php',
    'debug' => false,
    'update_branch' => '$BRANCH',
    // Test server on a copy of production data: uncomment both (see docs/TEST-SERVER.md)
    // 'staging' => true,
    // 'staging_mail_to' => 'align-test@example.com',
];
PHP
  umask 022
  chown root:www-data "$CONF_FILE"
  chmod 640 "$CONF_FILE"
fi

log "Applying database migrations"
as_www php "$APP_DIR/bin/align" migrate

ADMIN_PASS=""
if [[ "$(mariadb -N "$DB_NAME" -e 'SELECT COUNT(*) FROM users')" == "0" ]]; then
  ask ALIGN_ADMIN_EMAIL "Admin user email" ""
  ADMIN_PASS=$(rand 20)
  printf '%s\n' "$ADMIN_PASS" | as_www php "$APP_DIR/bin/align" user:create \
    --email="$ALIGN_ADMIN_EMAIL" --name="${ALIGN_ADMIN_NAME:-Administrator}" --role=admin --password-stdin >/dev/null
fi

# --------------------------------------------------------------------- PHP --
cat >"/etc/php/$PHPV/apache2/conf.d/99-msp-align.ini" <<'INI'
expose_php = Off
display_errors = Off
log_errors = On
memory_limit = 256M
max_execution_time = 120
upload_max_filesize = 8M
post_max_size = 8M
max_input_vars = 10000
session.cookie_httponly = 1
session.cookie_samesite = Lax
session.use_strict_mode = 1
session.use_only_cookies = 1
session.use_trans_sid = 0
session.gc_probability = 1
session.gc_divisor = 100
session.gc_maxlifetime = 86400
allow_url_fopen = Off
allow_url_include = Off
disable_functions = passthru,shell_exec,system,proc_open,popen,pcntl_exec,dl
INI
cat >"/etc/php/$PHPV/apache2/conf.d/99-msp-align-performance.ini" <<'INI'
; Managed by the MSP-ALIGN installer
opcache.enable = 1
opcache.memory_consumption = 128
opcache.interned_strings_buffer = 16
opcache.max_accelerated_files = 10000
opcache.validate_timestamps = 1
opcache.revalidate_freq = 2
realpath_cache_size = 4096K
realpath_cache_ttl = 600
INI

# ------------------------------------------------------------------ Apache --
log "Configuring Apache ($ALIGN_TLS)"
a2enmod -q headers ssl rewrite deflate >/dev/null
a2dissite -q 000-default >/dev/null 2>&1 || true

a2enmod -q reqtimeout >/dev/null 2>&1 || true
cat >/etc/apache2/conf-available/msp-align-hardening.conf <<'EOF'
# Managed by the MSP-ALIGN installer (rewritten on every update)
ServerTokens Prod
ServerSignature Off
TraceEnable Off
FileETag None
LimitRequestBody 10485760
Timeout 60
Header always unset X-Powered-By

# TLS 1.2+ with forward-secret AEAD ciphers only (Mozilla "intermediate")
<IfModule mod_ssl.c>
    SSLProtocol -all +TLSv1.2 +TLSv1.3
    SSLCipherSuite ECDHE-ECDSA-AES128-GCM-SHA256:ECDHE-RSA-AES128-GCM-SHA256:ECDHE-ECDSA-AES256-GCM-SHA384:ECDHE-RSA-AES256-GCM-SHA384:ECDHE-ECDSA-CHACHA20-POLY1305:ECDHE-RSA-CHACHA20-POLY1305
    SSLHonorCipherOrder off
    SSLSessionTickets off
    SSLCompression off
    SSLUseStapling on
    SSLStaplingCache "shmcb:${APACHE_RUN_DIR}/ssl_stapling(32768)"
</IfModule>

# Restoring a backup from Settings -> Updates & backups: allow large uploads on that one URL only,
# written to disk next to the app data instead of /tmp (a RAM disk on Debian 13)
<Location "/settings/system/upload">
    LimitRequestBody 2147483647
    <IfModule php_module>
        php_value upload_max_filesize 2000M
        php_value post_max_size 2000M
        php_value max_input_time 1800
        php_value max_execution_time 1800
        php_admin_value upload_tmp_dir /var/lib/msp-align/restore
    </IfModule>
</Location>

# Static files: page links carry ?v=<version>, so browsers can keep them for 30 days
<Directory /opt/msp-align/public/assets>
    Header set Cache-Control "public, max-age=2592000"
</Directory>
<Directory /opt/msp-align/public/vendor>
    Header set Cache-Control "public, max-age=2592000"
</Directory>

# HSTS on every HTTPS response
Header always set Strict-Transport-Security "max-age=31536000; includeSubDomains" "expr=%{HTTPS} == 'on'"

# Keep secret links (calendar feed tokens, invite links) out of the access log
SetEnvIf Request_URI "^/ics/" align_secret_url
SetEnvIf Request_URI "^/portal/invite/" align_secret_url

# REST API: make sure "Authorization: Bearer <key>" reaches PHP (PHP-FPM setups drop it otherwise)
SetEnvIf Authorization "(.+)" HTTP_AUTHORIZATION=$1
EOF
a2enconf -q msp-align-hardening >/dev/null

APP_BLOCK=$(cat <<EOF
    DocumentRoot $APP_DIR/public
    <Directory $APP_DIR/public>
        Options -Indexes +FollowSymLinks
        AllowOverride None
        Require all granted
        FallbackResource /index.php
    </Directory>
    <Directory $APP_DIR/public/assets>
        FallbackResource disabled
    </Directory>
    <FilesMatch "^\.">
        Require all denied
    </FilesMatch>
    ErrorLog \${APACHE_LOG_DIR}/msp-align-error.log
    CustomLog \${APACHE_LOG_DIR}/msp-align-access.log combined env=!align_secret_url
EOF
)
VHOST=/etc/apache2/sites-available/$SITE.conf

# The vhost is only written on first install (or with ALIGN_RECONFIGURE=1) so
# certbot's edits and any manual tuning survive upgrades.
WRITE_VHOST=1
[[ -f "$VHOST" && "${ALIGN_RECONFIGURE:-}" != "1" ]] && WRITE_VHOST=0

[[ $WRITE_VHOST == 1 ]] && case "$ALIGN_TLS" in
  selfsigned)
    CRT=/etc/ssl/certs/$SITE.crt; KEY=/etc/ssl/private/$SITE.key
    if [[ ! -f "$CRT" ]]; then
      openssl req -x509 -newkey rsa:3072 -sha256 -days 825 -nodes -subj "/CN=$ALIGN_FQDN" \
        -addext "subjectAltName=DNS:$ALIGN_FQDN" -keyout "$KEY" -out "$CRT" 2>/dev/null
      chmod 600 "$KEY"
    fi
    cat >"$VHOST" <<EOF
<VirtualHost *:80>
    ServerName $ALIGN_FQDN
    Redirect permanent / https://$ALIGN_FQDN/
</VirtualHost>
<VirtualHost *:443>
    ServerName $ALIGN_FQDN
    SSLEngine on
    SSLCertificateFile $CRT
    SSLCertificateKeyFile $KEY
    Header always set Strict-Transport-Security "max-age=31536000"
$APP_BLOCK
</VirtualHost>
EOF
    ;;
  letsencrypt)
    cat >"$VHOST" <<EOF
<VirtualHost *:80>
    ServerName $ALIGN_FQDN
$APP_BLOCK
</VirtualHost>
EOF
    ;;
  proxy)
    cat >"$VHOST" <<EOF
# Plain HTTP - TLS is terminated by the reverse proxy at ${ALIGN_PROXY_IP}.
<VirtualHost *:80>
    ServerName $ALIGN_FQDN
$APP_BLOCK
</VirtualHost>
EOF
    ;;
esac
# Older vhosts: stop logging secret URLs (the vhost itself is only written on first install)
[[ -f "$VHOST" ]] && sed -i -E 's#(CustomLog .*(mountaineer|msp)-align-access\.log combined)$#\1 env=!align_secret_url#' "$VHOST"

# Proxy mode: only the reverse proxy (and this machine) may talk to Apache, so nobody can bypass
# the proxy's TLS and WAF by connecting to port 80 directly.
if [[ "$ALIGN_TLS" == "proxy" && -n "${ALIGN_PROXY_IP:-}" ]]; then
  PROXY_LIST=$(echo "$ALIGN_PROXY_IP" | tr ',' ' ')
  cat >/etc/apache2/conf-available/msp-align-proxy-only.conf <<EOF
# Managed by the MSP-ALIGN installer
<Location "/">
    Require ip $PROXY_LIST 127.0.0.1 ::1
</Location>
EOF
  a2enconf -q msp-align-proxy-only >/dev/null
fi
a2ensite -q "$SITE" >/dev/null
apache2ctl configtest 2>&1 | grep -v "Syntax OK" || true
systemctl enable -q apache2
systemctl reload apache2 || systemctl restart apache2

if [[ "$ALIGN_TLS" == "letsencrypt" && ! -d "/etc/letsencrypt/live/$ALIGN_FQDN" ]]; then
  log "Requesting Let's Encrypt certificate"
  if [[ -z "${ALIGN_LE_EMAIL:-}" ]]; then   # an upgrade doesn't ask; only a first install does
    warn "No certificate yet and no email for Let's Encrypt - the site is on plain HTTP until you run: certbot --apache -d $ALIGN_FQDN"
  else
    certbot --apache -d "$ALIGN_FQDN" -m "$ALIGN_LE_EMAIL" --agree-tos --non-interactive --redirect \
      || warn "certbot failed - the site is on plain HTTP until you run: certbot --apache -d $ALIGN_FQDN"
  fi
fi

# ----------------------------------------------------------------- MariaDB --
log "Hardening MariaDB"
MYCNF=/etc/mysql/mariadb.conf.d/60-msp-align.cnf
KEYDIR=/etc/mysql/encryption
NEED_RESTART=0
{
  echo "# Managed by the MSP-ALIGN installer"
  echo "[mariadbd]"
  echo "bind-address = 127.0.0.1"
  echo "local-infile = 0"
} >"$MYCNF.new"
# Encryption at rest (HIPAA 164.312(a)(2)(iv)): InnoDB tables, redo log, temp files and Aria tables
# are encrypted with a key file readable only by the mysql user. Backups are logical dumps
# (encrypted separately with age), so restoring never needs this key.
if [[ "${ALIGN_DB_ENCRYPT:-1}" == "1" ]]; then
  if [[ ! -s "$KEYDIR/keyfile" ]]; then
    install -d -m 750 -o mysql -g mysql "$KEYDIR"
    ( umask 077; echo "1;$(openssl rand -hex 32)" >"$KEYDIR/keyfile" )
    chown mysql:mysql "$KEYDIR/keyfile"; chmod 400 "$KEYDIR/keyfile"
  fi
  cat >>"$MYCNF.new" <<EOF
plugin_load_add = file_key_management
file_key_management_filename = $KEYDIR/keyfile
file_key_management_encryption_algorithm = AES_CTR
innodb_encrypt_tables = ON
innodb_encrypt_log = ON
innodb_encrypt_temporary_tables = ON
innodb_encryption_threads = 2
encrypt_tmp_files = ON
encrypt_tmp_disk_tables = ON
aria_encrypt_tables = ON
EOF
fi
if ! cmp -s "$MYCNF.new" "$MYCNF" 2>/dev/null; then mv "$MYCNF.new" "$MYCNF"; NEED_RESTART=1; else rm -f "$MYCNF.new"; fi
if [[ $NEED_RESTART == 1 ]]; then
  systemctl restart mariadb || { warn "MariaDB failed to start with the new settings - reverting them"; rm -f "$MYCNF"; systemctl restart mariadb; }
fi
if [[ "${ALIGN_DB_ENCRYPT:-1}" == "1" && -f "$MYCNF" ]]; then
  # Rebuild existing tables so data written before encryption was turned on is encrypted too
  for t in $(mariadb -N -e "SELECT NAME FROM information_schema.INNODB_TABLESPACES_ENCRYPTION WHERE NAME LIKE '$DB_NAME/%' AND ENCRYPTION_SCHEME = 0" 2>/dev/null | sed "s#^$DB_NAME/##"); do
    mariadb "$DB_NAME" -e "ALTER TABLE \`$t\` ENCRYPTED=YES" >/dev/null 2>&1 || true
  done
fi

# --------------------------------------------------------- fail2ban & firewall --
rm -f /etc/fail2ban/filter.d/mountaineer-align.conf /etc/fail2ban/jail.d/mountaineer-align.conf   # names before 1.35
cat >/etc/fail2ban/filter.d/msp-align.conf <<'EOF'
# Failed MSP-ALIGN sign-ins (staff and client portal), logged by the app with the real client IP
# ([mountaineer-align] is how versions before 1.35 tagged them)
[Definition]
failregex = \[(?:msp|mountaineer)-align\] auth failure kind=\S+ ip=<HOST>
ignoreregex =
EOF
if [[ "$ALIGN_TLS" != "proxy" ]]; then
  cat >/etc/fail2ban/jail.d/msp-align.conf <<'EOF'
[msp-align]
enabled  = true
port     = http,https
filter   = msp-align
logpath  = /var/log/apache2/msp-align-error.log
backend  = auto
maxretry = 10
findtime = 10m
bantime  = 1h
EOF
else
  # Behind a proxy every request comes from the proxy's IP, so ban at the proxy/WAF instead.
  rm -f /etc/fail2ban/jail.d/msp-align.conf
fi
systemctl enable -q fail2ban 2>/dev/null || true
systemctl restart fail2ban 2>/dev/null || warn "fail2ban did not start - check: journalctl -u fail2ban"

if [[ "${ALIGN_FIREWALL:-1}" == "1" ]] && command -v ufw >/dev/null; then
  log "Configuring firewall (ufw)"
  SSH_PORTS=$(sshd -T 2>/dev/null | awk '/^port /{print $2}' | sort -u)
  for p in ${SSH_PORTS:-22}; do ufw limit "$p/tcp" >/dev/null; done
  if [[ "$ALIGN_TLS" == "proxy" ]]; then
    for ip in $(echo "${ALIGN_PROXY_IP:-}" | tr ',' ' '); do ufw allow from "$ip" to any port 80 proto tcp >/dev/null; done
  else
    ufw allow 80/tcp >/dev/null
    ufw allow 443/tcp >/dev/null
  fi
  ufw default deny incoming >/dev/null
  ufw default allow outgoing >/dev/null
  ufw --force enable >/dev/null
fi

# ----------------------------------------------------------------- systemd --
log "Installing scheduled jobs"
# 1.14: nightly backups on the server are replaced by backups downloaded through the browser
# 1.35: the units were named mountaineer-align-* (and the PSA poll timer -itflow before 1.34); they are now
# msp-align-*. Timers and the request watcher stop and go; a service that is running right now (this update
# runs inside mountaineer-align-agent.service) is never stopped, only disabled, and finishes normally.
for u in sync psa itflow mail nightly update-check; do
  if [[ -f /etc/systemd/system/mountaineer-align-$u.timer ]]; then
    systemctl disable -q --now "mountaineer-align-$u.timer" 2>/dev/null || true
    rm -f "/etc/systemd/system/mountaineer-align-$u.timer"
  fi
done
if [[ -f /etc/systemd/system/mountaineer-align-agent.path ]]; then
  systemctl disable -q --now mountaineer-align-agent.path 2>/dev/null || true
  rm -f /etc/systemd/system/mountaineer-align-agent.path
fi
for u in sync psa itflow mail nightly update-check agent; do
  if [[ -f /etc/systemd/system/mountaineer-align-$u.service ]]; then
    systemctl disable -q "mountaineer-align-$u.service" 2>/dev/null || true
    rm -f "/etc/systemd/system/mountaineer-align-$u.service"
  fi
done
if [[ -f /etc/systemd/system/mountaineer-align-backup.timer ]]; then
  systemctl disable -q --now mountaineer-align-backup.timer 2>/dev/null || true
  rm -f /etc/systemd/system/mountaineer-align-backup.timer /etc/systemd/system/mountaineer-align-backup.service
fi
install -m 644 "$APP_DIR"/deploy/systemd/*.service "$APP_DIR"/deploy/systemd/*.timer "$APP_DIR"/deploy/systemd/*.path /etc/systemd/system/
systemctl daemon-reload
systemctl enable -q --now msp-align-sync.timer msp-align-psa.timer msp-align-mail.timer \
  msp-align-nightly.timer msp-align-update-check.timer msp-align-agent.path
# First update check, in the background (waits for a running update to finish first)
systemctl start --no-block msp-align-update-check.service 2>/dev/null || true

# Commands: msp-align-update / msp-align-restore. The names from before the rename
# (mountaineer-align-update / -restore) are kept as aliases so scripts and habits keep working.
ln -sf "$APP_DIR/scripts/update.sh" /usr/local/sbin/msp-align-update
ln -sf "$APP_DIR/scripts/update.sh" /usr/local/sbin/mountaineer-align-update
cat >/usr/local/sbin/msp-align-restore <<EOF
#!/bin/sh
# Restore an MSP-ALIGN backup file (same as Settings -> Updates & backups -> Restore)
exec /usr/bin/php $APP_DIR/scripts/agent.php restore-cli "\$@"
EOF
chmod 750 /usr/local/sbin/msp-align-restore
ln -sf /usr/local/sbin/msp-align-restore /usr/local/sbin/mountaineer-align-restore
cat >/usr/local/bin/align <<EOF
#!/bin/sh
# MSP-ALIGN CLI (runs as www-data)
exec runuser -u www-data -- php $APP_DIR/bin/align "\$@"
EOF
chmod 755 /usr/local/bin/align

# ----------------------------------------------------------------- summary --
SCHEME=https; [[ "$ALIGN_TLS" == "proxy" ]] && SCHEME="https (via proxy)"
echo
printf '%s  MSP-ALIGN %s is ready.%s\n\n' "$c_ok$c_b" "$VERSION" "$c_0"
echo "  URL:       ${SCHEME%% *}://$ALIGN_FQDN/"
[[ "$ALIGN_TLS" == "proxy" ]] && echo "  Proxy:     point your reverse proxy at http://$(hostname -I | awk '{print $1}'):80 and forward X-Forwarded-Proto / X-Forwarded-For"
[[ "$ALIGN_TLS" == "selfsigned" ]] && echo "  TLS:       self-signed certificate - your browser will warn until you trust it or replace it"
if [[ -n "$ADMIN_PASS" ]]; then
  echo "  Admin:     $ALIGN_ADMIN_EMAIL"
  echo "  Password:  $ADMIN_PASS"
  printf '%s  Save this password now - it is not shown again. The first sign-in asks you to change it\n  and set up two-factor sign-in (required for every account).%s\n' "$c_warn" "$c_0"
fi
echo
echo "  Next:      sign in; the setup wizard walks through your company, integrations, email, clients and team"
echo "  Update:    sudo msp-align-update"
echo "  CLI:       sudo align help"
echo "  Backups:   Settings -> Updates & backups -> Download backup (encrypted; not kept on this server)"
if [[ -n "$BACKUP_PRIV_SHOWN" ]]; then
  echo
  printf '%s  BACKUP DECRYPTION KEY - store it offline now (password manager or safe).%s\n' "$c_warn$c_b" "$c_0"
  echo "  Without it the backups cannot be restored. It is also saved in $PRIVKEY_FILE;"
  echo "  delete that file once you have a copy:  sudo shred -u $PRIVKEY_FILE"
  echo
  grep '^AGE-SECRET-KEY' "$PRIVKEY_FILE" | sed 's/^/    /'
elif [[ -f "$PRIVKEY_FILE" ]]; then
  warn "The backup private key is still on this server ($PRIVKEY_FILE). Store it offline, then shred it."
fi
echo
