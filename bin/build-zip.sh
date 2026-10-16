#!/usr/bin/env bash
# Builds sebo-attribution.zip with the plugin in a sebo-attribution/ folder, as WordPress installs it.
set -euo pipefail
cd "$(dirname "$0")/.."
out="$PWD/sebo-attribution.zip"
tmp="$(mktemp -d)"
trap 'rm -rf "$tmp"' EXIT
mkdir "$tmp/sebo-attribution"
rsync -a --exclude .git --exclude .github --exclude tests --exclude bin --exclude '*.zip' --exclude .DS_Store ./ "$tmp/sebo-attribution/"
rm -f "$out"
(cd "$tmp" && zip -qr "$out" sebo-attribution)
echo "$out"
