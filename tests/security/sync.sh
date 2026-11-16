#!/bin/sh
# Copies the repo working tree into the test app (never config.php). admin/ and portal/
# are the running servers' document roots, so their contents are replaced in place.
SP="${DIS_TEST_DIR:?set DIS_TEST_DIR to the rig folder}"
cd "$(dirname "$0")/../.." || exit 1
for d in api includes js css images cron sql; do rm -rf "$SP/app/$d"; cp -r "$d" "$SP/app/$d"; done
for d in admin portal; do mkdir -p "$SP/app/$d"; find "$SP/app/$d" -mindepth 1 -maxdepth 1 -exec rm -rf {} +; cp -r "$d"/. "$SP/app/$d/"; rm -rf "$SP/app/$d/api" "$SP/app/$d/css" "$SP/app/$d/js" "$SP/app/$d/images"; done
cp index.php "$SP/app/" 2>/dev/null; true
