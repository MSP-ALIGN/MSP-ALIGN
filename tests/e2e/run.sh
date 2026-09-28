#!/usr/bin/env bash
# MSP-ALIGN end-to-end tests. Builds a throwaway install (see seed.py), starts the app, a second "fresh"
# app and the mock ITFlow / NinjaOne / Veeam / Microsoft / Google / Dell / Lenovo server, then runs every
# suite in order and prints a summary. Exits 1 if anything failed.
#
#   tests/e2e/run.sh                     # everything
#   tests/e2e/run.sh mapping_e2e rmm_e2e # just these suites (after a fresh seed)
#   KEEP=1 tests/e2e/run.sh backup_e2e   # reuse the last seed and servers
#
# Needs: PHP 8.4 (mysql, curl, mbstring, xml, intl, gd), MariaDB running with root on the
# unix socket, mariadb client tools, age, openssl, git, Python 3 with requests, pymysql, playwright
# (chromium) and openapi-spec-validator. Uses ports 8080, 8081 and 8099 and databases align_test,
# align_test_fresh, align_upg and align_snap (and a MariaDB user align_test), dropping them first, and
# stops whatever PHP server listens on those ports. Run it on a test machine only.
set -uo pipefail
ROOT=$(cd "$(dirname "$0")/../.." && pwd)
E2E=$ROOT/tests/e2e
export ALIGN_TEST_WORK=${ALIGN_TEST_WORK:-/tmp/msp-align-tests}
export ALIGN_TEST_DB=${ALIGN_TEST_DB:-align_test} ALIGN_TEST_DB_FRESH=${ALIGN_TEST_DB_FRESH:-align_test_fresh}
export ALIGN_TEST_SOCKET=${ALIGN_TEST_SOCKET:-/run/mysqld/mysqld.sock}
export ALIGN_TEST_URL=http://127.0.0.1:8080 ALIGN_TEST_URL_FRESH=http://127.0.0.1:8081 ALIGN_TEST_MOCK=http://127.0.0.1:8099
export PYTHONPATH=$E2E PYTHONUNBUFFERED=1
unset HTTP_PROXY HTTPS_PROXY http_proxy https_proxy ALL_PROXY all_proxy  # everything is local
W=$ALIGN_TEST_WORK
case "$ALIGN_TEST_DB $ALIGN_TEST_DB_FRESH" in align_test*\ align_test*) ;; *) echo "Test database names must start with align_test" >&2; exit 2 ;; esac
case "$(realpath -m "$W")" in /|/tmp|"$HOME"|"$ROOT"|"$ROOT"/*) echo "Refusing to use $W as the scratch folder" >&2; exit 2 ;; esac

SUITES=(portal_e2e sec_e2e e2e ex_e2e rep_e2e order_e2e mail_e2e google_e2e int_e2e legal_e2e replace_e2e dnd_e2e
  compliance_e2e sla_e2e dash_e2e onb_e2e hosted_e2e api_e2e api_ui_e2e api_attack_e2e psa_e2e rmm_e2e backup_e2e
  mapping_e2e sys_e2e upd_ui_e2e crawl crawl_fresh)
[[ $# -gt 0 ]] && SUITES=("$@")

serve() { # port docroot-args... (restarts whatever listens there)
  local port=$1; shift
  pkill -f "php .*-S 127.0.0.1:$port" 2>/dev/null; sleep 0.3
  (cd "$ROOT" && nohup php -d upload_max_filesize=64M -d post_max_size=64M -S 127.0.0.1:$port "$@" >>"$W/server-$port.log" 2>&1 &)
  for _ in $(seq 1 50); do
    # ours must be the one answering (another program on the port would make php -S exit)
    if pgrep -f "php .*-S 127.0.0.1:$port" >/dev/null && curl -s -o /dev/null "http://127.0.0.1:$port/"; then return 0; fi
    sleep 0.1
  done
  echo "Could not start the server on port $port (see $W/server-$port.log)" >&2; exit 2
}

if [[ -z "${KEEP:-}" ]]; then
  rm -rf "$W"; mkdir -p "$W"
  rm -f /tmp/itflow-mock-state.json /tmp/itflow-updates.log /tmp/graph-mock.json 2>/dev/null
  ALIGN_CONFIG=$W/config.php serve 8099 tests/mock-server.php
  python3 "$E2E/seed.py" || { echo "Seeding failed" >&2; exit 2; }
  ALIGN_CONFIG=$W/config.php serve 8080 -t public tests/dev-router.php
  ALIGN_CONFIG=$W/fresh.php serve 8081 -t public tests/dev-router.php
fi

[[ -f "$W/config.php" ]] || { echo "No seeded install in $W: run without KEEP first" >&2; exit 2; }
pass=0; failed=()
for s in "${SUITES[@]}"; do
  f=$E2E/suites/$s.py
  [[ -f $f ]] || { echo "No suite $s" >&2; failed+=("$s (missing)"); continue; }
  start=$SECONDS
  out=$(cd "$E2E" && timeout 900 python3 "$f" 2>&1 | tee "$W/$s.log"; exit "${PIPESTATUS[0]}"); rc=$?
  n=$(echo "$out" | grep -oE '^FAILURES: [0-9]+' | tail -1 | grep -oE '[0-9]+$')
  if [[ $rc -eq 0 && "$n" == "0" ]]; then
    pass=$((pass + 1)); printf 'ok    %-16s %4ss  %s\n' "$s" $((SECONDS - start)) "$(echo "$out" | grep -c '^PASS') checks"
  else
    failed+=("$s"); printf 'FAIL  %-16s %4ss\n' "$s" $((SECONDS - start))
    echo "$out" | grep -E '^FAIL |Traceback|Error' | head -8 | sed 's/^/        /'
  fi
done
echo; echo "$pass of ${#SUITES[@]} suites passed. Logs: $W/<suite>.log"
[[ ${#failed[@]} -eq 0 ]] || { echo "Failed: ${failed[*]}"; exit 1; }
