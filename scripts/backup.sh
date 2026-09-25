#!/usr/bin/env bash
# Nightly backup: database dump + config (config holds app_key, needed to decrypt stored secrets).
# Keeps 14 days. Copy /var/backups/mountaineer-align off this VM (e.g. with your backup agent).
set -Eeuo pipefail
DEST=/var/backups/mountaineer-align
KEEP_DAYS="${ALIGN_BACKUP_DAYS:-14}"
STAMP=$(date +%Y%m%d-%H%M%S)
umask 077
mkdir -p "$DEST"
mariadb-dump --single-transaction --quick --routines mountaineer_align | gzip -9 >"$DEST/db-$STAMP.sql.gz"
cp /etc/mountaineer-align/config.php "$DEST/config-$STAMP.php"
if [ -d /var/lib/mountaineer-align/uploads ]; then tar -czf "$DEST/uploads-$STAMP.tar.gz" -C /var/lib/mountaineer-align uploads; fi
find "$DEST" -type f \( -name 'db-*.sql.gz' -o -name 'config-*.php' -o -name 'uploads-*.tar.gz' \) -mtime +"$KEEP_DAYS" -delete
echo "Backup written: $DEST/db-$STAMP.sql.gz"
