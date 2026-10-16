#!/bin/sh
# Run PHPStan exactly as CI does (.github/workflows/ci.yml, job "phpstan"): the pinned, checksummed
# phar against a CLEAN copy of the committed tree (git archive HEAD plus the vendor/bbf submodule),
# so untracked files, a local config.php or cache/ cannot hide or add findings.
#
#   cli/phpstan.sh                       # analyse HEAD; downloads the pinned phar once into cache/
#   PHPSTAN_PHAR=/path/phpstan.phar cli/phpstan.sh
#
# It must report no errors. phpstan-baseline.neon may only shrink: PHPStan fails on baseline entries
# that no longer match (reportUnmatchedIgnoredErrors, on by default), so fixing a baselined finding
# means deleting its entry in the same change. Never add entries by hand; see phpstan.neon.dist.
# Uncommitted changes are NOT analysed: commit (or stash) them first.
set -eu

# Keep in sync with ci.yml (cli/quality-gates.php checks it).
PHPSTAN_VERSION='2.3.1'
PHPSTAN_SHA256='85157dba3d95d77ca603d2b7303aa78a538f9e3b56ccad07dd794c53aa96d1ae'

root=$(cd "$(dirname "$0")/.." && pwd)
phar=${PHPSTAN_PHAR:-"$root/cache/phpstan-$PHPSTAN_VERSION.phar"}
if [ ! -f "$phar" ]; then
    mkdir -p "$(dirname "$phar")"
    curl -fsSL -o "$phar.tmp" "https://github.com/phpstan/phpstan/releases/download/$PHPSTAN_VERSION/phpstan.phar"
    mv "$phar.tmp" "$phar"
fi
echo "$PHPSTAN_SHA256  $phar" | sha256sum -c - >/dev/null

work=$(mktemp -d)
trap 'rm -rf "$work"' EXIT INT TERM
git -C "$root" archive HEAD | tar -x -C "$work"
if [ -d "$root/vendor/bbf" ]; then
    mkdir -p "$work/vendor/bbf"
    cp -R "$root/vendor/bbf/." "$work/vendor/bbf/"
    rm -rf "$work/vendor/bbf/.git"
fi
cd "$work"
php -d memory_limit=2G "$phar" analyse -c phpstan.neon.dist --no-progress
