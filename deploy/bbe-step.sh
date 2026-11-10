#!/bin/sh
# bbe-step: run a BareBonesEshop CLI step that root starts as the owner of the application root, switched
# before any PHP of the shop runs (review round 28, finding 1).
#
# cli/cli-only.php switches a step started as root to that owner too, but it is code inside the tree: an
# account that can write the tree (often the web user) can change it, and root would run the changed copy.
# This wrapper lives outside the tree, so nothing in the tree decides whether root runs it. Install it
# root-owned outside the shop, e.g.:
#   install -o root -g root -m 0755 deploy/bbe-step.sh /usr/local/sbin/bbe-step
# and use it instead of `sudo php cli/<step>.php`:
#   bbe-step /var/www/shop update.php apply --package=/srv/releases/update-2.1.3
# Linux (util-linux `setpriv`). Elsewhere run the step as the owner yourself: sudo -u <owner> php cli/<step>.php ...
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

uid=$(stat -c %u "$root")
gid=$(stat -c %g "$root")
if [ "$uid" = 0 ]; then
    echo "bbe-step: REFUSED: $root belongs to root. Give the application root to the deploy or web account" \
        "(chown <owner>: $root) and run the step as that account; root never runs PHP of a shop tree." >&2
    exit 1
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
exec setpriv --reuid="$uid" --regid="$gid" "$groups" --inh-caps=-all -- php "$root/cli/$step" "$@"
