#!/usr/bin/env bash
# Usage: tools/release.sh 1.7.1 "What changed (shown on the Plugins page)"
# Sets the version in bd-smart-address.php, commits and pushes to main.
# The Release action sees the new version, tags vX.Y.Z, builds the zip and publishes it.
set -euo pipefail
cd "$(dirname "$0")/.."
VER="${1:?version, e.g. 1.7.1}"
NOTES="${2:-BD Smart Address $VER}"
sed -i -E "s/^( \* Version:\s+)[0-9.]+/\1$VER/; s/define\( 'BDSA_VERSION', '[0-9.]+' \)/define( 'BDSA_VERSION', '$VER' )/" bd-smart-address.php
grep -q "Version:           $VER" bd-smart-address.php && grep -q "'BDSA_VERSION', '$VER'" bd-smart-address.php || { echo "version not set"; exit 1; }
sed -i -E "1s/\(v[0-9.]+\)/(v$VER)/" README.txt
sed -i -E "s/^Current version: [0-9.]+/Current version: $VER/" README.md
git add -A
git commit -m "Release $VER" -m "$NOTES"
git push origin HEAD:main
echo "Pushed $VER — the Release action will publish v$VER with bd-smart-address.zip in about a minute."
