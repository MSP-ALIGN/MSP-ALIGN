#!/usr/bin/env bash
# Runs the end-to-end suites in a container with PHP 8.4 (tests/docker/e2e.Dockerfile), for machines whose own PHP is
# older, such as Ubuntu 24.04 with PHP 8.3, where the suites that use PHP 8.4 functions fail (2.38.3).
#
#   tests/docker/e2e.sh build            build the image (again)
#   tests/docker/e2e.sh [suite ...]      run tests/e2e/run.sh in the container: every suite, or the ones named
#   tests/docker/e2e.sh shell            a shell in the container
#   tests/docker/e2e.sh stop             remove the container (its databases go with it)
#
# KEEP and GROUP are passed to run.sh (and SHOTS, read by one-off screenshot scripts; point it under
# /tmp/msp-align-tests). The logs land in $LOGS on this machine (default /tmp/msp-align-tests), which is
# /tmp/msp-align-tests in the container; they're given back to the checkout's owner after each run. KEEP=1 needs the
# container from the last run (after `stop` the databases are gone). LOGS and PW_BROWSERS are read when the container
# is created: `stop` first to change them. Needs a full clone or a git worktree (its shared .git is mounted too).
#
# Settings (environment):
#   BASE        image to build on: debian:trixie (default, as CI) or another release with PHP 8.4 (ubuntu:25.10)
#   PROXY_CA    CA certificate of an HTTPS proxy that intercepts TLS; required with HTTPS_PROXY (build time only)
#   PW_BROWSERS a Playwright browsers folder to mount instead of downloading Chromium (default /opt/pw-browsers if it
#               exists; it must match Playwright 1.63)
#
# Security: the container runs with no network (the suites only use their own mock servers on localhost) and gets
# the checkout, the browsers (read-only) and the logs folder; no tokens or keys are passed in. The image holds no app
# code. It drops and recreates its own test databases, inside the container only.
set -euo pipefail
cd "$(dirname "$0")/../.."
ROOT=$PWD
IMAGE=msp-align-e2e:php84
NAME=msp-align-e2e
LOGS=${LOGS:-/tmp/msp-align-tests}
BASE=${BASE:-debian:trixie}
PW_BROWSERS=${PW_BROWSERS:-$([ -d /opt/pw-browsers ] && echo /opt/pw-browsers || true)}

# Docker must be running; a root shell without a service manager (a cloud dev container) can start the daemon itself
if ! docker info >/dev/null 2>&1; then
    if [ "$(id -u)" = 0 ] && command -v dockerd >/dev/null; then
        (dockerd >/tmp/dockerd.log 2>&1 &)
        for _ in $(seq 1 30); do docker info >/dev/null 2>&1 && break; sleep 1; done
    fi
    docker info >/dev/null 2>&1 || { echo "Docker isn't running." >&2; exit 1; }
fi

build() {
    local ctx
    ctx=$(mktemp -d)
    cp tests/docker/e2e.Dockerfile "$ctx/Dockerfile"
    if [ -n "${PROXY_CA:-}" ]; then cp "$PROXY_CA" "$ctx/proxy-ca.crt"; else : >"$ctx/proxy-ca.crt"; fi
    # The build reaches the network the way this machine does (through HTTPS_PROXY on 127.0.0.1 when set)
    docker build --network host --build-arg BASE="$BASE" --build-arg HTTPS_PROXY="${HTTPS_PROXY:-${https_proxy:-}}" \
        --build-arg BROWSER="$([ -n "$PW_BROWSERS" ] && echo 0 || echo 1)" -t "$IMAGE" "$ctx"
    rm -rf "$ctx"
}

# The container stays up between runs (so KEEP=1 can reuse the last seed), with MariaDB started in it
up() {
    docker image inspect "$IMAGE" >/dev/null 2>&1 || build
    if [ "$(docker inspect -f '{{.State.Running}}' "$NAME" 2>/dev/null)" != true ]; then
        docker rm -f "$NAME" >/dev/null 2>&1 || true
        mkdir -p "$LOGS"
        local mounts=(-v "$ROOT:$ROOT" -v "$LOGS:/tmp/msp-align-tests") common
        # A linked worktree's .git points into the main repository's .git, which the seed's git commands need
        common=$(cd "$ROOT" && realpath "$(git rev-parse --git-common-dir)")
        case "$common" in "$ROOT"/*) ;; *) mounts+=(-v "$common:$common") ;; esac
        [ -n "$PW_BROWSERS" ] && mounts+=(-v "$PW_BROWSERS:/opt/pw-browsers:ro" -e PLAYWRIGHT_BROWSERS_PATH=/opt/pw-browsers)
        docker run -d --init --name "$NAME" --network none --shm-size=1g "${mounts[@]}" -w "$ROOT" "$IMAGE" sleep infinity >/dev/null
    fi
    docker exec "$NAME" bash -c 'mysqladmin ping >/dev/null 2>&1 || {
        mkdir -p /run/mysqld && chown mysql:mysql /run/mysqld
        ($(command -v mariadbd-safe || command -v mysqld_safe) >/dev/null 2>&1 &)
        for i in $(seq 1 30); do mysqladmin ping >/dev/null 2>&1 && break; sleep 1; done
        mysqladmin ping >/dev/null; }'
}

case "${1:-}" in
    build) build ;;
    stop) docker rm -f "$NAME" >/dev/null 2>&1 || true ;;
    shell) up; docker exec -it "$NAME" bash ;;
    *)
        up
        rc=0
        docker exec -e KEEP -e GROUP -e SHOTS "$NAME" bash tests/e2e/run.sh "$@" || rc=$?
        # Everything in the container runs as root: hand the logs back to whoever owns the checkout
        docker exec "$NAME" chown -R "$(stat -c %u:%g "$ROOT")" /tmp/msp-align-tests 2>/dev/null || true
        exit "$rc" ;;
esac
