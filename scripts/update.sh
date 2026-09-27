#!/usr/bin/env bash
# MSP-ALIGN. Copyright (C) 2026 Mountaineer IT Inc. SPDX-License-Identifier: AGPL-3.0-or-later (see LICENSE)
# Updates MSP-ALIGN from GitHub: safety copy of the data, latest code, then the installer
# in upgrade mode (packages, migrations, services). The safety copy is deleted once the update
# succeeds. Same as Settings -> Updates & backups -> Update.   Usage: sudo msp-align-update
set -Eeuo pipefail
[[ $EUID -eq 0 ]] || { echo "Run with sudo." >&2; exit 1; }
exec /usr/bin/php /opt/mountaineer-align/scripts/agent.php update-cli
