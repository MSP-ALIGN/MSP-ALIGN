#!/usr/bin/env bash
# Tests the dedicated-server install (install.sh) in a throwaway Debian 13 "VM" (a privileged container running
# systemd), so the Docker work can't break it (1.44):
#   1. a fresh install of this checkout
#   2. an install of the previous release, updated to this checkout with msp-align-update
# Both must end with the site answering, the timers running and nothing Docker-specific in the server's config;
# the update must leave config.php, the Apache site and PHP settings exactly as they were.
#
#   tests/docker/install_test.sh [OLD_REF]      (default: the latest v* tag before HEAD)
# Needs docker. BASE=debian:trixie by default (override for a local mirror image).
set -euo pipefail
cd "$(dirname "$0")/../.."
ROOT=$PWD
BASE=${BASE:-debian:trixie}
OLD_REF=${1:-$(git describe --tags --abbrev=0 --match 'v*' HEAD^ 2>/dev/null || true)}
[[ -n "$OLD_REF" ]] || { echo "No earlier release tag found; pass OLD_REF"; exit 1; }
W=$(mktemp -d)
fails=0
ok()   { if eval "$1"; then echo "PASS $2"; else echo "FAIL $2"; fails=$((fails + 1)); fi; }
vm()   { docker exec vm bash -c "$1"; }
cleanup() { docker rm -f vm >/dev/null 2>&1 || true; rm -rf "$W"; }
trap cleanup EXIT

echo "== building the test VM ($BASE)"
docker build -q ${DOCKER_BUILD_ARGS:-} --build-arg BASE="$BASE" -t msp-align-vm:test -f tests/docker/vm.Dockerfile tests/docker >/dev/null

# The code comes from a bare copy of this checkout: the new code on ci-new, the previous release on ci-old
git clone -q --bare "$ROOT" "$W/src.git"
if [[ -n "$(git status --porcelain)" ]]; then
  # uncommitted changes (a local run): commit them into the copy only
  git worktree add -q --detach "$W/wt" HEAD
  rsync -a --delete --exclude .git "$ROOT/" "$W/wt/"
  (cd "$W/wt" && git add -A && git -c user.name=ci -c user.email=ci@example.com commit -qm "uncommitted changes" && git push -q "$W/src.git" HEAD:refs/heads/ci-new)
  git worktree remove --force "$W/wt"
else
  git push -q "$W/src.git" "HEAD:refs/heads/ci-new"
fi
git -C "$W/src.git" branch -f ci-old "$OLD_REF"
NEW_VERSION=$(git -C "$W/src.git" show ci-new:VERSION)
OLD_VERSION=$(git -C "$W/src.git" show ci-old:VERSION)
echo "== old: $OLD_REF ($OLD_VERSION)  new: $NEW_VERSION"

boot() {
  docker rm -f vm >/dev/null 2>&1 || true
  docker run -d --name vm --privileged --cgroupns=host -v /sys/fs/cgroup:/sys/fs/cgroup:rw --tmpfs /run --tmpfs /run/lock \
    -v "$W/src.git":/src.git:ro msp-align-vm:test >/dev/null
  local up=0
  for _ in $(seq 60); do
    if vm 'case $(systemctl is-system-running 2>/dev/null) in running|degraded) exit 0;; *) exit 1;; esac' 2>/dev/null; then up=1; break; fi
    sleep 1
  done
  [[ $up == 1 ]] || { echo "FAIL systemd didn't start in the test VM"; docker logs vm 2>&1 | tail -20; exit 1; }
  vm 'git config --global --add safe.directory "*"'
}
install_branch() {   # fresh install of a branch; the installer then updates from that branch (not GitHub)
  vm "git clone -q --branch $1 /src.git /opt/msp-align && ALIGN_FQDN=align.test ALIGN_TLS=selfsigned ALIGN_ADMIN_EMAIL=admin@example.com ALIGN_TZ=UTC ALIGN_FIREWALL=0 ALIGN_BRANCH=$1 ${ALIGN_EXTRA:-} bash /opt/msp-align/install.sh >/root/install.log 2>&1" \
    || { vm 'tail -30 /root/install.log'; return 1; }
}
check_mode() {
  vm 'php /opt/msp-align/scripts/agent.php check >/dev/null 2>&1; grep -q "\"docker\": true" /var/lib/msp-align-agent/update.json && echo docker || echo git'
}
checks() {   # $1 = expected version
  ok "[[ \$(vm 'curl -sk -o /dev/null -w %{http_code} --resolve align.test:443:127.0.0.1 https://align.test/login') == 200 ]]" "the site answers over HTTPS"
  ok "vm 'curl -sk --resolve align.test:443:127.0.0.1 https://align.test/login' | grep -q 'MSP Align'" "the sign-in page is MSP Align"
  ok "[[ \$(vm 'cat /opt/msp-align/VERSION') == '$1' ]]" "version $1"
  ok "[[ \$(vm 'systemctl list-timers --all --no-legend' | grep -c 'msp-align-') -ge 5 ]]" "the scheduled jobs are systemd timers"
  for unit in apache2 mariadb msp-align-agent.path; do ok "vm 'systemctl is-active --quiet $unit'" "$unit is running"; done
  ok "! vm 'grep -q install_type /etc/msp-align/config.php'" "nothing Docker-specific in config.php"
  ok "vm 'runuser -u www-data -- php /opt/msp-align/bin/align migrate' | grep -q 'up to date'" "database migrations all applied"
  ok "vm 'runuser -u www-data -- php /opt/msp-align/bin/align audit:verify' | grep -q 'intact'" "audit log intact"
  ok "[[ \$(check_mode) == git ]]" "the update check uses git, not the Docker check"
}

echo "== 1. fresh install of the new code"
boot
if install_branch ci-new; then echo "PASS fresh install"; checks "$NEW_VERSION"; else echo "FAIL fresh install"; fails=$((fails + 1)); fi

echo "== 2. install $OLD_VERSION, then update to the new code"
boot
if install_branch ci-old; then
  echo "PASS install $OLD_VERSION"
  vm 'sha256sum /etc/msp-align/config.php /etc/apache2/sites-available/msp-align.conf /etc/php/*/apache2/conf.d/99-msp-align.ini >/root/before.sha'
  if vm "ALIGN_FIREWALL=0 ALIGN_BRANCH=ci-new ${ALIGN_EXTRA:-} msp-align-update >/root/update.log 2>&1"; then
    echo "PASS msp-align-update to the new code"
  else
    echo "FAIL msp-align-update to the new code"; vm 'tail -30 /root/update.log'; fails=$((fails + 1))
  fi
  ok "vm 'sha256sum -c --quiet /root/before.sha'" "config.php, the Apache site and PHP settings unchanged by the update"
  checks "$NEW_VERSION"
else
  echo "FAIL install $OLD_VERSION"; fails=$((fails + 1))
fi

echo
echo "$fails failure(s)"
exit $(( fails > 0 ))
