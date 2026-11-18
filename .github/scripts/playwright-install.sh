#!/usr/bin/env bash
# Installs Playwright browsers and their system packages on a GitHub runner (round 45, ideas #24/#39).
#   bash .github/scripts/playwright-install.sh chromium webkit firefox
# The runner's apt mirror can stall for minutes per package (whole jobs failed at ~36 min downloading ffmpeg libraries
# at 30 KB/s): the downloaded .deb files are kept in $PLAYWRIGHT_APT_CACHE, which the workflow restores and saves
# with actions/cache together with the browsers (~/.cache/ms-playwright), so a warm job downloads no package and no
# browser. Each attempt is time-limited and retried instead of hanging the job.
set -u
[ "$#" -gt 0 ] || { echo 'usage: playwright-install.sh <browser>...' >&2; exit 2; }
debs="${PLAYWRIGHT_APT_CACHE:-$HOME/.cache/playwright-apt}"
mkdir -p "$debs/partial"
printf 'Acquire::Retries "5";\nAcquire::http::Timeout "20";\nAcquire::https::Timeout "20";\nDir::Cache::Archives "%s";\nAPT::Keep-Downloaded-Packages "true";\nBinary::apt::APT::Keep-Downloaded-Packages "true";\n' "$debs" \
    | sudo tee /etc/apt/apt.conf.d/99-ci-playwright >/dev/null
# Runner images may delete downloaded packages after every install.
sudo rm -f /etc/apt/apt.conf.d/docker-clean
for attempt in 1 2 3; do
    if timeout 480 npx playwright install --with-deps "$@"; then
        # apt wrote the cache as root: let actions/cache (the runner user) read it when the job ends.
        sudo rm -f "$debs/lock"
        sudo chmod -R a+rX "$debs"
        exit 0
    fi
    echo "::warning::playwright install attempt $attempt did not finish; retrying"
    # timeout stops npx, not the apt-get it started: give that a few minutes to finish (it holds the dpkg lock),
    # then stop a stalled one so the retry can take the lock.
    timeout 240 bash -c 'while pgrep -x apt-get >/dev/null || pgrep -x dpkg >/dev/null; do sleep 10; done' || true
    sudo pkill -x apt-get || true
    sleep 5
    sudo dpkg --configure -a || true
done
exit 1
