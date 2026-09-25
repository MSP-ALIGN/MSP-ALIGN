#!/usr/bin/env bash
# Nightly backup, encrypted with age to the public key in /etc/mountaineer-align/backup-recipient.txt.
# Only the PUBLIC key lives on this server; the private key (made at install) is needed to restore,
# so a stolen backup is unreadable. Also checks the audit log for tampering and prunes entries past
# the 6-year retention period. Keeps 14 days of backups. Copy /var/backups/mountaineer-align off
# this VM with your backup agent.
#
# Restore:  age -d -i backup-key.txt db-STAMP.sql.gz.age | gunzip | mariadb mountaineer_align
set -Eeuo pipefail
DEST=/var/backups/mountaineer-align
RECIPIENT_FILE=/etc/mountaineer-align/backup-recipient.txt
KEEP_DAYS="${ALIGN_BACKUP_DAYS:-14}"
STAMP=$(date +%Y%m%d-%H%M%S)
umask 077
mkdir -p "$DEST"
chmod 700 "$DEST"

if [[ -s "$RECIPIENT_FILE" ]] && command -v age >/dev/null; then
  enc() { age -R "$RECIPIENT_FILE" -o "$1"; }
  SUFFIX=.age
else
  echo "WARNING: no backup encryption key ($RECIPIENT_FILE) - writing an UNENCRYPTED backup. Run: sudo mountaineer-align-update" >&2
  enc() { cat >"$1"; }
  SUFFIX=
fi

mariadb-dump --single-transaction --quick --routines mountaineer_align | gzip -9 | enc "$DEST/db-$STAMP.sql.gz$SUFFIX"
enc "$DEST/config-$STAMP.php$SUFFIX" </etc/mountaineer-align/config.php
if [ -d /var/lib/mountaineer-align/uploads ]; then
  tar -czf - -C /var/lib/mountaineer-align uploads | enc "$DEST/uploads-$STAMP.tar.gz$SUFFIX"
fi
find "$DEST" -type f \( -name 'db-*' -o -name 'config-*' -o -name 'uploads-*' \) -mtime +"$KEEP_DAYS" -delete
echo "Backup written: $DEST/db-$STAMP.sql.gz$SUFFIX"

# Audit log: tamper check (the head hash is recorded in the system journal as an outside checkpoint) and retention
runuser -u www-data -- php /opt/mountaineer-align/bin/align audit:verify || echo "ALERT: audit log verification failed" >&2
runuser -u www-data -- php /opt/mountaineer-align/bin/align audit:prune
