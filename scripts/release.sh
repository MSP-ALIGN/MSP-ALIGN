#!/usr/bin/env bash
# MSP Align. Copyright (C) 2026 Mountaineer IT Inc. and MSP Align contributors. SPDX-License-Identifier: AGPL-3.0-or-later (see LICENSE)
#
# Signed releases (2.0). Checks release tags in a checkout against the release signers file.
#
#   release.sh APP_DIR SIGNERS_FILE [CURRENT_VERSION]
#       The newest release tag (vX.Y.Z, no pre-releases) newer than CURRENT_VERSION (any, without it) that passes the
#       checks below. Prints "TAG COMMIT" (e.g. "v2.0.1 3f9c...") and exits 0; exits 3 when there is none. Newer tags
#       that fail are listed on stderr as "UNSIGNED vX.Y.Z" (at most 20), so the caller can warn: they are never used.
#   release.sh APP_DIR SIGNERS_FILE --head
#       Exits 0 when the checkout's HEAD is the commit of a release tag that passes the checks, with a matching VERSION.
#
# A tag passes when it is an annotated tag named exactly like its ref, carries an SSH signature (no OpenPGP or X.509)
# made by a key in SIGNERS_FILE, and points at a commit whose VERSION file says the same version.
# SIGNERS_FILE is the one already installed (deploy/release-signers), never one from the download: a release can add
# or replace keys only when it is itself signed by a key that is trusted now. Exit 2: no usable signers file; 4: no
# ssh-keygen. The caller fetches the tags first (and holds the agent lock).
set -uo pipefail
APP=$1 SIGNERS=$2 ARG=${3:-0}
grep -qvE '^[[:space:]]*(#|$)' "$SIGNERS" 2>/dev/null || { echo "No release key in $SIGNERS" >&2; exit 2; }
command -v ssh-keygen >/dev/null || { echo "ssh-keygen is missing (install openssh-client)" >&2; exit 4; }
# git with signature checking pinned to SSH and to SIGNERS: OpenPGP and X.509 checks run "false", so only an SSH
# signature by a key in SIGNERS can pass. Runs as root on the root-owned checkout.
git_v() { git -C "$APP" -c gpg.ssh.program=ssh-keygen -c gpg.program=false -c gpg.x509.program=false -c gpg.ssh.allowedSignersFile="$SIGNERS" "$@"; }

# A before B? (X.Y.Z; a pre-release X.Y.Z-anything comes before X.Y.Z, as PHP's version_compare says)
older() {
  local a=${1%%-*} b=${2%%-*}
  if [[ "$a" == "$b" ]]; then [[ "$1" == *-* && "$2" != *-* ]]; return; fi
  [[ "$(printf '%s\n%s\n' "$a" "$b" | sort -V | head -1)" == "$a" ]]
}
# Prints the commit of a tag that passes the checks (returns 0), or nothing (returns 1). The caller installs that commit
# by its hash, so a tag moved after this check can't change what is installed.
passes() {
  local tag=$1 ver=${1#v} oid body
  oid=$(git -C "$APP" rev-parse -q --verify "refs/tags/$tag" 2>/dev/null) || return 1
  [[ "$(git -C "$APP" cat-file -t "$oid" 2>/dev/null)" == tag ]] || return 1
  body=$(git -C "$APP" cat-file tag "$oid" 2>/dev/null) || return 1
  sed '/^$/q' <<<"$body" | grep -qFx "tag $tag" || return 1          # the signed name (header) is the ref's name
  grep -qFx -- '-----BEGIN SSH SIGNATURE-----' <<<"$body" || return 1
  git_v verify-tag "$oid" >/dev/null 2>&1 || return 1
  [[ "$(git -C "$APP" show "$oid^{commit}:VERSION" 2>/dev/null | tr -d '[:space:]')" == "$ver" ]] || return 1
  git -C "$APP" rev-parse "$oid^{commit}"
}

if [[ "$ARG" == --head ]]; then
  head=$(git -C "$APP" rev-parse HEAD) || exit 1
  ver=$(tr -d '[:space:]' <"$APP/VERSION" 2>/dev/null)
  [[ "$ver" =~ ^[0-9]+\.[0-9]+\.[0-9]+$ ]] || exit 1
  [[ "$(passes "v$ver")" == "$head" ]]
  exit
fi

n=0 listed=0
while read -r tag; do
  [[ "$tag" =~ ^v[0-9]+\.[0-9]+\.[0-9]+$ ]] || continue
  [[ "$ARG" == 0 ]] || older "$ARG" "${tag#v}" || break
  if commit=$(passes "$tag"); then
    echo "$tag $commit"
    exit 0
  fi
  if (( listed < 20 )); then echo "UNSIGNED $tag" >&2; listed=$((listed + 1)); fi
  n=$((n + 1)); (( n < 200 )) || break                               # a flood of junk tags can't stall the check
done < <(git -C "$APP" tag -l 'v*' --sort=-v:refname)
exit 3
