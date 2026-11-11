#!/bin/sh
# bbe-step: run a BareBonesEshop CLI step that root starts as the owner of the application root, switched
# before any PHP of the shop runs (review round 28, finding 1). A tree root owns runs as root only when no other
# account can change it (review round 29, finding 1).
#
# cli/cli-only.php switches a step started as root to that owner too, but it is code inside the tree: an
# account that can write the tree (often the web user) can change it, and root would run the changed copy.
# This wrapper lives outside the tree, so nothing in the tree decides whether root runs it. Install it
# root-owned outside the shop, e.g.:
#   install -o root -g root -m 0755 deploy/bbe-step.sh /usr/local/sbin/bbe-step
# and use it instead of `sudo php cli/<step>.php`:
#   bbe-step /var/www/shop update.php apply --package=/srv/releases/update-2.1.3
# Linux (util-linux `setpriv`, `setsid`). Elsewhere run the step as the owner yourself: sudo -u <owner> php cli/<step>.php ...
# with sudo's `Defaults use_pty` (the default from sudo 1.9.14), or the step can type into root's terminal.
set -eu

if [ "$#" -lt 2 ]; then
    echo "usage: bbe-step <application root> <step>.php [arguments...]" >&2
    exit 2
fi
root=$1
step=$2
shift 2

case $root in
    /*) ;;
    *) echo "bbe-step: the application root must be an absolute path: $root" >&2; exit 2 ;;
esac
case $step in
    *[!A-Za-z0-9_.-]* | .*) echo "bbe-step: the step must be a file name in cli/ ending in .php: $step" >&2; exit 2 ;;
    *.php) ;;
    *) echo "bbe-step: the step must be a file name in cli/ ending in .php: $step" >&2; exit 2 ;;
esac
if [ ! -d "$root" ] || [ -L "$root" ]; then
    echo "bbe-step: $root is not a directory" >&2
    exit 2
fi

if [ "$(id -u)" != 0 ]; then
    # Not root: nothing to give up.
    exec php "$root/cli/$step" "$@"
fi

if ! command -v setsid >/dev/null 2>&1; then
    echo "bbe-step: REFUSED: setsid (util-linux) is not installed." >&2
    exit 1
fi
uid=$(stat -c %u "$root")
gid=$(stat -c %g "$root")
if [ "$uid" = 0 ]; then
    # Review round 29, finding 1: a tree root owns alone is root's own code, and its owner is root, so there is no one to
    # switch to (R28 refused it, and such a shop could not be updated at all). Root runs it only when no other account can
    # change it: every directory above it and every entry in it belongs to root without group or other write (an ACL
    # mask shows as group bits), a link stays inside the tree. The step lock is never loaded, only locked (2.1.3 made it
    # 0660 root:root). This check is outside the tree, so nothing in the tree decides it.
    real=$(readlink -f -- "$root")
    case $real in
        *[!A-Za-z0-9_./-]*) echo "bbe-step: REFUSED: $real: only letters, digits and _ . - / are accepted in a root-owned application root" >&2; exit 1 ;;
    esac
    dir=$real
    while [ "$dir" != / ]; do
        dir=$(dirname -- "$dir")
        if [ "$(stat -c %u "$dir")" != 0 ] || { [ -n "$(find "$dir" -maxdepth 0 -perm /022 ! -perm -1000)" ]; }; then
            echo "bbe-step: REFUSED: $dir, above the root-owned $real, can be changed by another account; root runs no code under it." >&2
            exit 1
        fi
    done
    bad=$(find "$real" \( \( -path "$real/.maintenance.lock" -o -path "$real/.maintenance.lock.held" \) -type f -user 0 ! -perm /002 -links 1 \) -prune \
        -o \( -type l ! -user 0 \) -print -o \( ! -type l \( ! -user 0 -o -perm /022 \) \) -print 2>&1 | head -n 1)
    if [ -z "$bad" ]; then
        bad=$(find "$real" -type l -exec sh -c 'for l; do t=$(readlink -f -- "$l") || t=; case $t in "$0" | "$0"/*) ;;
            *) printf "%s (a symbolic link out of the tree: %s)\n" "$l" "${t:-dangling}" ;; esac; done' "$real" {} + | head -n 1)
    fi
    if [ -n "$bad" ]; then
        echo "bbe-step: REFUSED: $real belongs to root, but $bad can be changed by another account, and root would run code of it." \
            "For a tree only root may change: chmod -R go-w $real (extract a release as root with tar --no-same-permissions);" \
            "otherwise chown it to the deploy or web account and run the step as that account." >&2
        exit 1
    fi
    exec setsid -w php "$real/cli/$step" "$@"
fi
if [ "$gid" = 0 ]; then
    # A tree of group root (www-data:root) must not hand the step group 0: take the owner's primary group.
    gid=$(id -g "$uid" 2>/dev/null || echo 0)
    if [ "$gid" = 0 ]; then
        echo "bbe-step: REFUSED: $root has group root and its owner (uid $uid) has no other primary group;" \
            "chgrp the application root to the owner's group." >&2
        exit 1
    fi
fi
if ! command -v setpriv >/dev/null 2>&1; then
    echo "bbe-step: REFUSED: setpriv (util-linux) is not installed; run the step as the owner yourself:" \
        "sudo -u \"#$uid\" php $root/cli/$step ..." >&2
    exit 1
fi

home=$(getent passwd "$uid" 2>/dev/null | cut -d: -f6 || true)
if [ -n "$home" ]; then
    export HOME="$home" # mysqldump & co. read the owner's ~/.my.cnf, not root's
    groups=--init-groups
    case " $(id -G "$uid" 2>/dev/null || echo 0) " in
        *" 0 "*) echo "bbe-step: REFUSED: the owner of $root (uid $uid) is in group root; the step would keep it." >&2; exit 1 ;;
    esac
else
    groups=--clear-groups
fi
# The step runs in a session of its own, without root's terminal as its controlling terminal: code the owner can change
# must not push input into root's shell (TIOCSTI) once the step ends. Ctrl-C or a dropped ssh connection does not stop
# it; stop it with kill. The step keeps the terminal as stdin/stdout/stderr, so its prompts and output still work.
exec setsid -w setpriv --reuid="$uid" --regid="$gid" "$groups" --inh-caps=-all -- php "$root/cli/$step" "$@"
