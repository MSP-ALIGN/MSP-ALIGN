#!/usr/bin/env bash
# MSP-ALIGN. Copyright (C) 2026 Mountaineer IT Inc. and MSP-ALIGN contributors. SPDX-License-Identifier: AGPL-3.0-or-later (see LICENSE)
# Updates MSP-ALIGN from GitHub: safety copy of the data, latest code, then the installer
# in upgrade mode (packages, migrations, services). The safety copy is deleted once the update
# succeeds. Same as Settings -> Updates & backups -> Update.   Usage: sudo msp-align-update
set -Eeuo pipefail
[[ $EUID -eq 0 ]] || { echo "Run with sudo." >&2; exit 1; }
APP=/opt/msp-align
[[ -d $APP ]] || APP=/opt/mountaineer-align   # a server that hasn't moved to the msp-align folders yet (before 1.35)
exec /usr/bin/php "$APP/scripts/agent.php" update-cli
