#!/usr/bin/env bash
# =============================================================================
#  Mountaineer Align - installer / upgrader for Debian 13 (trixie)
#
#  Fresh install (private repo), run on a new Debian 13 VM:
#    read -rs GH_TOKEN && export GH_TOKEN
#    curl -fsSL -H "Authorization: Bearer $GH_TOKEN" \
#      https://raw.githubusercontent.com/MountaineerIT/mountaineer-align/main/install.sh | sudo -E bash
#
#  Upgrade an existing install:
#    sudo mountaineer-align-update
#
#  Unattended install: set any of these env vars to skip the matching prompt
#    GH_TOKEN  ALIGN_FQDN  ALIGN_TLS (selfsigned|letsencrypt|proxy)  ALIGN_LE_EMAIL
#    ALIGN_PROXY_IP  ALIGN_ADMIN_EMAIL  ALIGN_ADMIN_NAME  ALIGN_TZ
#    ALIGN_REPO (owner/name)  ALIGN_BRANCH  ALIGN_FORCE=1 (skip OS check)
# =============================================================================
set -Eeuo pipefail

REPO="${ALIGN_REPO:-MountaineerIT/mountaineer-align}"
BRANCH="${ALIGN_BRANCH:-main}"
APP_DIR=/opt/mountaineer-align
CONF_DIR=/etc/mountaineer-align
CONF_FILE="$CONF_DIR/config.php"
TOKEN_FILE="$CONF_DIR/github-token"
DATA_DIR=/var/lib/mountaineer-align
BACKUP_DIR=/var/backups/mountaineer-align
DB_NAME=mountaineer_align
DB_USER=align
SITE=mountaineer-align

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

if [[ -f "$CONF_FILE" && "$MODE" == "install" ]]; then
  log "Existing install found - running as an upgrade."
  MODE=upgrade
fi

echo
printf '%s  Mountaineer Align %s%s\n' "$c_b" "$MODE" "$c_0"
printf '%s  repo %s (%s) -> %s%s\n\n' "$c_dim" "$REPO" "$BRANCH" "$APP_DIR" "$c_0"

# ------------------------------------------------------------------ inputs --
if [[ "$MODE" == "upgrade" ]]; then
  command -v php >/dev/null || die "PHP not found - is this really an existing install?"
  ALIGN_FQDN=$(php_conf_get fqdn)
  ALIGN_TLS=$(php_conf_get tls_mode)
  ALIGN_PROXY_IP=$(php_conf_get trusted_proxies)
else
  ask GH_TOKEN "GitHub read-only token for $REPO (blank if the repo is public)" "" secret
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
  ask ALIGN_TZ "Time zone" "$(timedatectl show -p Timezone --value 2>/dev/null || echo America/Los_Angeles)"
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
PKGS=(apache2 libapache2-mod-php php-cli php-mysql php-curl php-mbstring php-xml php-intl
      mariadb-server git ca-certificates curl openssl unattended-upgrades)
[[ "${ALIGN_TLS:-}" == "letsencrypt" ]] && PKGS+=(certbot python3-certbot-apache)
apt-get install -y -qq "${PKGS[@]}" >/dev/null
PHPV=$(php -r 'echo PHP_MAJOR_VERSION.".".PHP_MINOR_VERSION;')
php -r 'exit(function_exists("sodium_crypto_secretbox") ? 0 : 1);' || die "PHP sodium extension missing."

# Automatic security updates
cat >/etc/apt/apt.conf.d/20auto-upgrades <<'EOF'
APT::Periodic::Update-Package-Lists "1";
APT::Periodic::Unattended-Upgrade "1";
EOF

# ------------------------------------------------------------- directories --
install -d -m 750 -o root -g www-data "$CONF_DIR"
install -d -m 750 -o www-data -g www-data "$DATA_DIR"
install -d -m 700 -o www-data -g www-data "$DATA_DIR/sessions"
install -d -m 700 -o root -g root "$BACKUP_DIR"

if [[ -n "${GH_TOKEN:-}" ]]; then
  umask 077; printf '%s' "$GH_TOKEN" >"$TOKEN_FILE"; umask 022
  chmod 600 "$TOKEN_FILE"
fi

# -------------------------------------------------------------------- code --
CRED_HELPER="!f() { test \"\$1\" = get || exit 0; test -s $TOKEN_FILE || exit 0; echo username=x-access-token; echo password=\$(cat $TOKEN_FILE); }; f"
if [[ -d "$APP_DIR/.git" ]]; then
  log "Updating code"
  git -C "$APP_DIR" config credential.helper "$CRED_HELPER"
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
chmod -R go-w "$APP_DIR"
chmod 755 "$APP_DIR/bin/align" "$APP_DIR/scripts/"*.sh
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
// Mountaineer Align server config - generated by install.sh on $(date -I)
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
    'php_cli' => '/usr/bin/php',
    'debug' => false,
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
cat >"/etc/php/$PHPV/apache2/conf.d/99-mountaineer-align.ini" <<'INI'
expose_php = Off
display_errors = Off
log_errors = On
memory_limit = 256M
max_execution_time = 120
upload_max_filesize = 8M
post_max_size = 8M
session.cookie_httponly = 1
session.use_strict_mode = 1
session.gc_probability = 1
session.gc_divisor = 100
session.gc_maxlifetime = 28800
INI

# ------------------------------------------------------------------ Apache --
log "Configuring Apache ($ALIGN_TLS)"
a2enmod -q headers ssl rewrite >/dev/null
a2dissite -q 000-default >/dev/null 2>&1 || true

cat >/etc/apache2/conf-available/mountaineer-align-hardening.conf <<'EOF'
ServerTokens Prod
ServerSignature Off
TraceEnable Off
EOF
a2enconf -q mountaineer-align-hardening >/dev/null

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
    ErrorLog \${APACHE_LOG_DIR}/mountaineer-align-error.log
    CustomLog \${APACHE_LOG_DIR}/mountaineer-align-access.log combined
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
a2ensite -q "$SITE" >/dev/null
apache2ctl configtest 2>&1 | grep -v "Syntax OK" || true
systemctl enable -q apache2
systemctl reload apache2 || systemctl restart apache2

if [[ "$ALIGN_TLS" == "letsencrypt" && ! -d "/etc/letsencrypt/live/$ALIGN_FQDN" ]]; then
  log "Requesting Let's Encrypt certificate"
  certbot --apache -d "$ALIGN_FQDN" -m "$ALIGN_LE_EMAIL" --agree-tos --non-interactive --redirect \
    || warn "certbot failed - the site is on plain HTTP until you run: certbot --apache -d $ALIGN_FQDN"
fi

# ----------------------------------------------------------------- systemd --
log "Installing scheduled jobs"
install -m 644 "$APP_DIR"/deploy/systemd/*.service "$APP_DIR"/deploy/systemd/*.timer /etc/systemd/system/
systemctl daemon-reload
systemctl enable -q --now mountaineer-align-sync.timer mountaineer-align-backup.timer

ln -sf "$APP_DIR/scripts/update.sh" /usr/local/sbin/mountaineer-align-update
cat >/usr/local/bin/align <<EOF
#!/bin/sh
# Mountaineer Align CLI (runs as www-data)
exec runuser -u www-data -- php $APP_DIR/bin/align "\$@"
EOF
chmod 755 /usr/local/bin/align

# ----------------------------------------------------------------- summary --
SCHEME=https; [[ "$ALIGN_TLS" == "proxy" ]] && SCHEME="https (via proxy)"
echo
printf '%s  Mountaineer Align %s is ready.%s\n\n' "$c_ok$c_b" "$VERSION" "$c_0"
echo "  URL:       ${SCHEME%% *}://$ALIGN_FQDN/"
[[ "$ALIGN_TLS" == "proxy" ]] && echo "  Proxy:     point your reverse proxy at http://$(hostname -I | awk '{print $1}'):80 and forward X-Forwarded-Proto / X-Forwarded-For"
[[ "$ALIGN_TLS" == "selfsigned" ]] && echo "  TLS:       self-signed certificate - your browser will warn until you trust it or replace it"
if [[ -n "$ADMIN_PASS" ]]; then
  echo "  Admin:     $ALIGN_ADMIN_EMAIL"
  echo "  Password:  $ADMIN_PASS"
  printf '%s  Save this password now - it is not shown again. Turn on 2FA under Account after signing in.%s\n' "$c_warn" "$c_0"
fi
echo
echo "  Next:      Settings -> add NinjaOne + ITFlow API keys -> Test -> Sync"
echo "  Update:    sudo mountaineer-align-update"
echo "  CLI:       sudo align help"
echo "  Backups:   $BACKUP_DIR (nightly; includes config.php, which holds the encryption key)"
echo
