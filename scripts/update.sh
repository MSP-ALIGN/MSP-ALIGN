#!/usr/bin/env bash
# Pulls the latest code, then re-runs the installer in upgrade mode
# (packages, migrations, services). Usage: sudo mountaineer-align-update
set -Eeuo pipefail
APP_DIR=/opt/mountaineer-align
BRANCH="${ALIGN_BRANCH:-main}"
[[ $EUID -eq 0 ]] || { echo "Run with sudo." >&2; exit 1; }
OLD=$(cat "$APP_DIR/VERSION")
echo "==> Backing up before update"
"$APP_DIR/scripts/backup.sh"
echo "==> Fetching latest code ($BRANCH)"
git -C "$APP_DIR" fetch -q origin "$BRANCH"
git -C "$APP_DIR" reset -q --hard "origin/$BRANCH"
NEW=$(cat "$APP_DIR/VERSION")
echo "==> $OLD -> $NEW"
exec bash "$APP_DIR/install.sh" --upgrade
