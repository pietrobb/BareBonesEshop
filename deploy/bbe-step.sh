#!/bin/bash
# bbe-step: run a BareBonesEshop CLI step that root starts as the owner of the application root, switched
# before any PHP of the shop runs (review round 28, finding 1). A tree root owns runs as root only when no other
# account can change it (review round 29, finding 1).
#
# cli/cli-only.php switches a step started as root to that owner too, but it is code inside the tree: an
# account that can write the tree (often the web user) can change it, and root would run the changed copy.
# This wrapper lives outside the tree, so nothing in the tree decides whether root runs it. Install it
# root-owned outside the shop from a release you verified (the signed package or the release tag), not from the
# shop's own copy, which an account that can write the tree may have changed, e.g.:
#   install -o root -g root -m 0755 /srv/releases/update-2.1.3/payload/deploy/bbe-step.sh /usr/local/sbin/bbe-step
# and use it instead of `sudo php cli/<step>.php`:
#   bbe-step /var/www/shop update.php apply --package=/srv/releases/update-2.1.3
# The step gets no terminal (stdin is /dev/null: pass --yes to restore.php), only the variables BBE_*, BAREBONES_*,
# SMTP_*, MAIL_*, PAY_*, LANG, LC_* and TZ of root's environment, and no other open file of root's; it runs in a pid
# namespace that ends with it, and Ctrl-C stops it (review round 29, finding 3), also after setpriv changed the user
# (review round 30, finding 1), as does a SIGTERM to the wrapper alone (review round 32). It runs in the application root, so a relative path in an argument is taken from there.
# Linux (bash, util-linux `setpriv`, `setsid`, `unshare`). Elsewhere run the step as the owner yourself:
# sudo -u <owner> php cli/<step>.php ... with sudo's `Defaults use_pty` (the default from sudo 1.9.14).
# Review round 32, low 1: every tool came from root's PATH, looked up after `cd` into the tree, so an empty entry or `.`
# in it (PATH=$EXTRA:$PATH with an empty EXTRA) ran the owner's own `setsid` as uid 0. Only the system directories count,
# so php must be in one of them.
PATH=/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin
export PATH
if [ -z "${BASH_VERSION:-}" ]; then exec bash "$0" "$@"; fi
set -euo pipefail

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

for tool in setsid setpriv; do
    if ! command -v "$tool" >/dev/null 2>&1; then
        echo "bbe-step: REFUSED: $tool (util-linux) is not installed; run the step as the owner yourself:" \
            "sudo -u <owner> php $root/cli/$step ... (with sudo's Defaults use_pty)." >&2
        exit 1
    fi
done
php=$(command -v php) || { echo "bbe-step: php is not in $PATH (link it into /usr/local/bin)" >&2; exit 1; }

# Review round 29, finding 3: the step kept root's terminal as stdin/stdout/stderr (a process it left running read
# what root typed after the prompt came back, e.g. mysql -pHunter2), Ctrl-C stopped only the wrapper, and the step got
# root's environment and open files. Now: stdin is /dev/null and stdout/stderr are pipes that root's own `cat` copies
# to the terminal, so no process of the step holds a descriptor of root's terminal; only the variables a step reads
# pass; every other descriptor is closed; and the step is pid 1 of a pid namespace of its own, which the kernel
# empties when the step ends or the wrapper is stopped (Ctrl-C). Without pid namespaces (a container that
# forbids unshare) the wrapper waits until nothing of the step holds its output.
priv=() # setpriv … -- for a tree of another owner; nothing for a tree root owns alone
run_step() { # run_step <script> <arguments...>
    local envs=(PATH="/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin:$(dirname -- "$php")" HOME="$step_home")
    local name
    for name in $(compgen -e); do
        case $name in
            BBE_SUITE_*) ;;
            BBE_* | BAREBONES_* | SMTP_* | MAIL_* | PAY_* | LANG | LC_* | TZ) envs+=("$name=${!name}") ;;
        esac
    done
    local ns=()
    if unshare --pid --fork --kill-child=SIGKILL true 2>/dev/null; then
        ns=(unshare --pid --fork --kill-child=SIGKILL --)
    else
        echo "bbe-step: note: no pid namespace here (unshare refused); a process the step leaves running keeps running." >&2
    fi
    # Review round 32, low 2: only the inner shell below trapped signals, so a SIGTERM to this process alone (kill <pid>,
    # a supervisor, or sudo relaying one) ended the wrapper and left the step running. The pipeline runs in the
    # background, so `wait` returns on a signal, and this shell passes it on to the inner shell, which stops the step.
    local pidfile
    pidfile=$(mktemp)
    (
    {
        (
            printf '%s\n' "$BASHPID" >"$pidfile"
            for fd in /proc/$BASHPID/fd/*; do
                fd=${fd##*/}
                case $fd in 0 | 1 | 2 | 3 | *[!0-9]*) ;; *) eval "exec $fd>&-" 2>/dev/null || true ;; esac
            done
            # R30 (low): the step kept root's working directory; it runs in the application root, as `php cli/<step>.php`.
            cd -- "${1%/cli/*}"
            pending=
            trap 'pending=1' INT TERM HUP # a signal before stop_step can know the step: stop it right after the start
            setsid -w "${ns[@]}" setsid -w "${priv[@]}" env -i "${envs[@]}" "$php" "$@" </dev/null 2>&1 1>&3 3>&- &
            # unshare and the step are each in a session of their own, so no Ctrl-C reaches them: stop them here.
            # Review round 30, finding 1: killing unshare alone relied on the parent-death signal of --kill-child, which
            # the kernel clears when setpriv changes the user, so the owner's step ran on after the prompt came back (and a
            # SIGHUP to the terminal's group killed unshare before this trap could find pid 1). Kill pid 1 of the namespace
            # itself (the kernel then kills every process in it) and the session's process group; unshare is stopped first,
            # so it forks no pid 1 the scan could miss. Without a namespace this kills the step and its group.
            started=$!
            stop_step() {
                local stat line fields pid
                kill -STOP "$started" 2>/dev/null || true
                for stat in /proc/[0-9]*/stat; do
                    # Review round 32, low 3: the whole file, not its first line: a step can put a newline into its
                    # command name (/proc/self/comm), and the fields after it then were never read.
                    line=
                    IFS= read -r -d '' line 2>/dev/null <"$stat" || [ -n "$line" ] || continue
                    read -r -a fields <<<"${line##*) }" # state ppid pgrp …, after the last ") " (the name may hold one)
                    if [ "${fields[1]:-}" = "$started" ]; then
                        pid=${stat#/proc/}
                        pid=${pid%/stat}
                        kill -KILL "$pid" 2>/dev/null || true
                        kill -KILL -- "-$pid" 2>/dev/null || true
                    fi
                done
                kill -KILL "$started" 2>/dev/null || true
                kill -KILL -- "-$started" 2>/dev/null || true
            }
            trap stop_step INT TERM HUP
            [ -z "$pending" ] || stop_step
            status=0
            wait "$started" || status=$?
            while kill -0 "$started" 2>/dev/null; do wait "$started" || status=$?; done
            exit "$status"
        ) | cat >&2
    } 3>&1 | cat
    ) &
    local pipeline=$! status=0
    pass_on() {
        local inner= try
        for try in 1 2 3 4 5 6 7 8 9 10; do
            read -r inner <"$pidfile" 2>/dev/null || true
            [ -z "$inner" ] || break
            sleep 0.1
        done
        [ -z "$inner" ] || kill -TERM "$inner" 2>/dev/null || true
    }
    trap pass_on INT TERM HUP
    wait "$pipeline" || status=$?
    while kill -0 "$pipeline" 2>/dev/null; do wait "$pipeline" || status=$?; done
    trap - INT TERM HUP
    rm -f -- "$pidfile"
    return "$status"
}

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
    step_home=$(getent passwd 0 | cut -d: -f6 || true)
    step_home=${step_home:-/root}
    run_step "$real/cli/$step" "$@"
    exit 0
fi

# The owner needs an account: its home (mysqldump & co. read the owner's ~/.my.cnf, not root's) and its groups.
account=$(getent passwd "$uid" 2>/dev/null || true)
if [ -z "$account" ]; then
    echo "bbe-step: REFUSED: the owner of $root (uid $uid) has no account (getent passwd $uid), so it has no groups the" \
        "step could keep; create the account or run the step as that uid yourself." >&2
    exit 1
fi
step_home=$(printf '%s\n' "$account" | cut -d: -f6)
owner_groups=" $(id -G "$uid") "
case $owner_groups in
    *" 0 "*) echo "bbe-step: REFUSED: the owner of $root (uid $uid) is in group root; the step would keep it." >&2; exit 1 ;;
esac
# R29 (low): the step took the tree's group (e.g. disk) even when the owner is not in it. It keeps the tree's group only
# when the owner is a member; else (and for group root, R28) the owner's primary group.
case $owner_groups in
    *" $gid "*) ;;
    *) gid=$(id -g "$uid") ;;
esac
if [ "$gid" = 0 ]; then
    echo "bbe-step: REFUSED: the primary group of the owner of $root (uid $uid) is root; give it another one." >&2
    exit 1
fi
priv=(setpriv --reuid="$uid" --regid="$gid" --init-groups --inh-caps=-all --)
run_step "$root/cli/$step" "$@"
