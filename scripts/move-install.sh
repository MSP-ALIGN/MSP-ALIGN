#!/usr/bin/env bash
# MSP-ALIGN. Copyright (C) 2026 Mountaineer IT Inc. and MSP-ALIGN contributors. SPDX-License-Identifier: AGPL-3.0-or-later (see LICENSE)
#
# 1.35: moves an install from the old mountaineer-align names to msp-align. Run by install.sh --upgrade
# before anything else. Every step checks first, so it can run again after an interruption, and it does
# nothing on a server that has already moved (or was installed as msp-align).
#
#   /opt/mountaineer-align            -> /opt/msp-align             (code)
#   /etc/mountaineer-align            -> /etc/msp-align             (config, GitHub token, backup key)
#   /var/lib/mountaineer-align        -> /var/lib/msp-align         (uploads, sessions, downloads)
#   /var/lib/mountaineer-align-agent  -> /var/lib/msp-align-agent   (update and backup jobs)
#   /run/mountaineer-align            -> /run/msp-align             (requests from the web app)
#
# Each old folder is left as a link to the new one, so scripts, habits and anything still running keep
# working. Apache, PHP, MariaDB, fail2ban and tmpfiles files with the old name are renamed and their paths
# updated (certbot's -le-ssl site too). The database, its user and old backups in /var/backups keep their
# names. systemd units are swapped by install.sh itself (the update runs inside the old agent unit).
#
# ALIGN_ROOT=/some/dir runs it against a copy of the file tree (tests); it then touches nothing else.
#
# Security: runs as root, from the installed code or a trusted copy (install.sh). It never goes inside the folders the
# web user can write (the data folder, /run/.../requests): those are only renamed as a whole. Every other file it
# reads, copies or rewrites is root-owned. Nothing is deleted before its new copy is complete.
set -Eeuo pipefail
R="${ALIGN_ROOT:-}"
OLD=mountaineer-align
NEW=msp-align
moved=0

# --pending: exit 0 when anything this script moves still has an old name (a folder that isn't a link yet, or a file)
if [[ "${1:-}" == "--pending" ]]; then
  for d in /opt/$OLD /etc/$OLD /var/lib/$OLD /var/lib/$OLD-agent /run/$OLD; do
    [[ -d "$R$d" && ! -L "$R$d" ]] && exit 0
  done
  for f in /root/$OLD-backup-key.txt /etc/ssl/certs/$OLD.crt /etc/ssl/private/$OLD.key \
      /etc/apache2/sites-available/$OLD.conf /etc/apache2/sites-available/$OLD-le-ssl.conf \
      /etc/apache2/conf-available/$OLD-hardening.conf /etc/apache2/conf-available/$OLD-proxy-only.conf \
      /etc/mysql/mariadb.conf.d/60-$OLD.cnf /etc/fail2ban/filter.d/$OLD.conf /etc/fail2ban/jail.d/$OLD.conf /etc/tmpfiles.d/$OLD.conf \
      "$R"/etc/php/*/apache2/conf.d/99-"$OLD"*.ini; do
    f=${f#"$R"}
    [[ -e "$R$f" || -L "$R$f" ]] && exit 0
  done
  exit 1
fi

# Reports a step that changed something (and counts it, for the closing line); stops the move with a message.
say() { printf '==> %s\n' "$*"; moved=$((moved + 1)); }
die() { printf 'ERROR: %s\n' "$*" >&2; exit 1; }

# Old paths and log names in a text file's contents -> new ones (the database name has an underscore and is kept).
# A path only changes when the name ends there (/etc/mountaineer-align-extra is someone else's and is left alone).
# A filter from stdin to stdout; it reads only root-owned config files (never anything in the web user's folders).
edit() {
  sed -E \
    -e "s#/(opt|etc|run|var/lib)/$OLD(-agent)?([/\"' \t;,>)]|\$)#/\1/$NEW\2\3#g" \
    -e "s#$OLD-(error|access)\.log#$NEW-\1.log#g" \
    -e "s#/etc/ssl/certs/$OLD\.crt#/etc/ssl/certs/$NEW.crt#g" \
    -e "s#/etc/ssl/private/$OLD\.key#/etc/ssl/private/$NEW.key#g" \
    -e "s#^\[$OLD\]\$#[$NEW]#" \
    -e "s#^(filter *= *)$OLD\$#\1$NEW#" \
    -e 's#\\\[mountaineer-align\\\]#\\[(?:msp|mountaineer)-align\\]#g'
}

# A folder: move it and leave a link behind. Refuses when both exist (something to sort out by hand).
# mv renames the folder itself (both names are in the same root-owned parent), so nothing inside it is read or
# followed, including the data folder the web user owns. When the two are on different file systems, mv copies first
# and removes the old folder only after the copy is complete, so a stop half way leaves both: the next run then
# refuses ("Both ... exist") instead of losing anything.
move_dir() {
  local o="$R$1" n="$R$2"
  if [[ -L "$o" ]]; then
    [[ -e "$n" ]] || die "$1 links to $2, which is missing."
    return 0
  fi
  [[ -e "$o" ]] || return 0
  [[ ! -e "$n" ]] || die "Both $1 and $2 exist. Move what you need into $2, remove $1 and run the update again."
  mv -T "$o" "$n"
  ln -s "$n" "$o"
  say "Moved $1 to $2 ($1 now links there)"
}

# A config file: write it under the new name with paths updated (same owner and mode), then remove the old one.
# $3 = "raw" keeps the contents as they are (binary or secret files).
# The new file is built as NEW.tmp and renamed into place, so NEW only ever exists complete: a run stopped half way
# can't leave a partial copy that the next run would keep (it keeps an existing NEW and puts OLD aside). NEW.tmp
# starts as a copy of OLD (cp -p), so it has OLD's owner and mode before any edited contents go in: a secret file
# is never readable by anyone OLD wasn't. Only root-owned files outside the web user's folders come through here.
move_file() {
  local o="$R$1" n="$R$2"
  [[ -e "$o" || -L "$o" ]] || return 0
  if [[ -e "$n" ]]; then
    # The new one is already in place (an earlier run stopped half way): keep the old one aside, not loaded
    mv -f "$o" "$o.pre-1.35"
    say "Kept $1 as $1.pre-1.35 ($2 was already there)"
    return 0
  fi
  if [[ -L "$o" ]]; then
    ln -s "$(readlink "$o" | edit)" "$n"
  else
    rm -f "$n.tmp"
    cp -p "$o" "$n.tmp"
    if [[ "${3:-}" != raw ]]; then
      edit <"$o" >"$n.tmp"   # writing over the copy keeps its owner and mode
    fi
    mv -f "$n.tmp" "$n"
  fi
  rm -f "$o"
  say "Renamed $1 to $2"
}

# An Apache site or conf: rename it, and its enabled link if there is one (kept relative, as a2ensite makes it).
move_apache() {
  local kind=$1 name=$2   # kind: sites | conf
  local avail="/etc/apache2/$kind-available" enabled="/etc/apache2/$kind-enabled"
  local newname=${name/$OLD/$NEW}
  local was_enabled=0
  [[ -e "$R$enabled/$name.conf" || -L "$R$enabled/$name.conf" ]] && was_enabled=1
  move_file "$avail/$name.conf" "$avail/$newname.conf"
  if [[ $was_enabled == 1 ]]; then
    rm -f "$R$enabled/$name.conf"
    [[ -e "$R$enabled/$newname.conf" ]] || ln -s "../$kind-available/$newname.conf" "$R$enabled/$newname.conf"
  fi
}

# ---- folders (code first: this script and install.sh keep running from the moved files)
move_dir "/opt/$OLD" "/opt/$NEW"
move_dir "/etc/$OLD" "/etc/$NEW"
move_dir "/var/lib/$OLD-agent" "/var/lib/$NEW-agent"
move_dir "/var/lib/$OLD" "/var/lib/$NEW"
move_dir "/run/$OLD" "/run/$NEW"

# ---- the server config's own paths (sessions, uploads)
# Built beside it with its owner and mode (cp -p), then renamed over it: config.php holds app_key, and rewriting it in
# place could leave it cut short if the move were stopped right then.
CONF="$R/etc/$NEW/config.php"
if [[ -f "$CONF" ]] && grep -Eq "/(opt|etc|run|var/lib)/$OLD" "$CONF"; then
  rm -f "$CONF.tmp"
  cp -p "$CONF" "$CONF.tmp"
  edit <"$CONF" >"$CONF.tmp"
  mv -f "$CONF.tmp" "$CONF"
  say "Updated the paths in /etc/$NEW/config.php"
fi

# ---- the backup private key the installer printed (only there until the admin shreds it)
move_file "/root/$OLD-backup-key.txt" "/root/$NEW-backup-key.txt" raw

# ---- self-signed certificate (the site file is updated to match below)
move_file "/etc/ssl/certs/$OLD.crt" "/etc/ssl/certs/$NEW.crt" raw
move_file "/etc/ssl/private/$OLD.key" "/etc/ssl/private/$NEW.key" raw

# ---- Apache: the site (and certbot's HTTPS copy of it), the hardening and proxy-only confs
move_apache sites "$OLD"
move_apache sites "$OLD-le-ssl"
move_apache conf "$OLD-hardening"
move_apache conf "$OLD-proxy-only"

# ---- PHP, MariaDB, fail2ban, tmpfiles
for ini in "$R"/etc/php/*/apache2/conf.d/99-"$OLD"*.ini; do
  [[ -e "$ini" ]] || continue
  rel=${ini#"$R"}
  move_file "$rel" "${rel/$OLD/$NEW}"
done
move_file "/etc/mysql/mariadb.conf.d/60-$OLD.cnf" "/etc/mysql/mariadb.conf.d/60-$NEW.cnf"
move_file "/etc/fail2ban/filter.d/$OLD.conf" "/etc/fail2ban/filter.d/$NEW.conf"
move_file "/etc/fail2ban/jail.d/$OLD.conf" "/etc/fail2ban/jail.d/$NEW.conf"
move_file "/etc/tmpfiles.d/$OLD.conf" "/etc/tmpfiles.d/$NEW.conf"
# /run is emptied at every boot: keep the old /run path as a link after a reboot too (install.sh writes the same)
TMPF="$R/etc/tmpfiles.d/$NEW.conf"
if [[ -L "$R/opt/$OLD" && -f "$TMPF" ]] && ! grep -q "^L /run/$OLD " "$TMPF"; then
  echo "L /run/$OLD - - - - /run/$NEW" >>"$TMPF"
fi

[[ $moved -gt 0 ]] && echo "==> MSP-ALIGN now lives in /opt/$NEW, /etc/$NEW and /var/lib/$NEW (the old folder names still work)"
exit 0
