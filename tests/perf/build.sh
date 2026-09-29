#!/usr/bin/env bash
# Builds a large install for performance testing: database align_test_perf, served on port 8085, with the
# scaled mock APIs (tests/perf/mock-scale.php) on port 8098. Times the first sync and the other jobs.
#   tests/perf/build.sh                       # 150 clients, about 10,000 devices
#   PERF_CLIENTS=300 PERF_DEVICES=25000 tests/perf/build.sh
# Then: python3 tests/perf/timing.py  (times every page for each role)
# Same needs as tests/e2e/run.sh. Test machines only: it drops and recreates the database.
set -euo pipefail
ROOT=$(cd "$(dirname "$0")/../.." && pwd)
export PERF_CLIENTS=${PERF_CLIENTS:-150} PERF_DEVICES=${PERF_DEVICES:-10000}
export TZ=${ALIGN_TEST_TZ:-America/Los_Angeles}
W=${ALIGN_PERF_WORK:-/tmp/msp-align-perf}
DB=align_test_perf
SOCKET=${ALIGN_TEST_SOCKET:-/run/mysqld/mysqld.sock}
unset HTTP_PROXY HTTPS_PROXY http_proxy https_proxy ALL_PROXY all_proxy
mkdir -p "$W"/{uploads,sessions,sys/data,sys/agent,sys/run}
my() { mysql -uroot --socket="$SOCKET" "$@"; }
my -e "create user if not exists 'align_test'@'localhost' identified by 'testpass';
  drop database if exists $DB; create database $DB character set utf8mb4 collate utf8mb4_unicode_ci; grant all on $DB.* to 'align_test'@'localhost'"
cat > "$W/config.php" <<PHP
<?php
return ['db' => ['host' => 'localhost', 'name' => '$DB', 'user' => 'align_test', 'pass' => 'testpass'],
  'app_key' => 'base64:$(head -c32 /dev/urandom | base64)', 'timezone' => '$TZ', 'trusted_proxies' => [],
  'upload_path' => '$W/uploads', 'session_path' => '$W/sessions', 'php_cli' => '$(command -v php)',
  'data_dir' => '$W/sys/data', 'agent_dir' => '$W/sys/agent', 'run_dir' => '$W/sys/run',
  'fqdn' => 'align.test', 'debug' => true, 'allow_insecure_integrations' => true];
PHP
export ALIGN_CONFIG=$W/config.php
serve() { # port args...
  local port=$1; shift
  for p in $(pgrep -f "php -S 127.0.0.1:$port"); do kill "$p" 2>/dev/null || true; done
  sleep 0.3
  (nohup php -S "127.0.0.1:$port" "$@" >"$W/server-$port.log" 2>&1 &)
  for _ in $(seq 50); do curl -s -o /dev/null "http://127.0.0.1:$port/" && return; sleep 0.1; done
}
rm -f "$(php -r 'echo sys_get_temp_dir();')"/align-perf-mock-*
serve 8098 "$ROOT/tests/perf/mock-scale.php"
serve 8085 -t "$ROOT/public" "$ROOT/tests/dev-router.php"
t() { local s=$SECONDS; "$@"; echo "   took $((SECONDS - s))s: $*"; }
php "$ROOT/bin/align" migrate >/dev/null
php "$ROOT/tests/perf/setup.php"
echo "sync (first run, $PERF_CLIENTS clients, ~$PERF_DEVICES devices):"
t php "$ROOT/bin/align" sync --quiet
php "$ROOT/tests/perf/plan.php"
echo "sync (second run, nothing new):"
t php "$ROOT/bin/align" sync --quiet
echo "jobs:"
t php "$ROOT/bin/align" psa:poll --quiet
t php "$ROOT/bin/align" mail:run --force --quiet
t php "$ROOT/bin/align" audit:verify
my -N $DB -e "select 'clients', count(*) from clients union all select 'devices', count(*) from devices union all select 'psa assets', count(*) from psa_assets
  union all select 'tickets', count(*) from psa_tickets union all select 'contacts', count(*) from contacts union all select 'licenses', count(*) from licenses
  union all select 'backup workloads', count(*) from backup_workloads union all select 'm365 objects', count(*) from backup_m365_objects
  union all select 'warranty lookups', count(*) from warranty_lookups union all select 'audit rows', count(*) from audit_log"
echo "ready: http://127.0.0.1:8085 (admin@example.com / LongPassword123!)"
