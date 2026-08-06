#!/usr/bin/env bash
set -euo pipefail
ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
VERSION="${1:-}"
if [[ -z "$VERSION" ]]; then
  VERSION="$(awk '/^[[:space:]]*\*[[:space:]]*Version:/{print $3; exit}' "$ROOT/persiano-hub.php")"
fi
OUT="${2:-$ROOT/../persiano-hub-v${VERSION}.zip}"
TMP="$(mktemp -d)"
trap 'rm -rf "$TMP"' EXIT
mkdir -p "$TMP/persiano-hub"
rsync -a "$ROOT/" "$TMP/persiano-hub/" \
  --exclude '.git/' --exclude '.github/' --exclude 'build/' --exclude 'dist/' \
  --exclude '*.zip' --exclude '.DS_Store'
( cd "$TMP" && zip -qr "$OUT" persiano-hub )
printf 'Created %s\n' "$OUT"
