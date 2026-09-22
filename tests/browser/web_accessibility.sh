#!/usr/bin/env bash
set -euo pipefail

BASE_URL="${1:-${COS_E2E_BASE_URL:-http://127.0.0.1:8081}}"
OUT_DIR="${COS_A11Y_OUTPUT_DIR:-tmp/web-accessibility}"
AXE_VERSION="4.13.0"
WCAG_TAGS="wcag2a,wcag2aa,wcag21a,wcag21aa,wcag22aa"

mkdir -p "$OUT_DIR"

scan() {
  local name="$1"
  local path="$2"
  local target="${BASE_URL%/}$path"
  echo "axe ${AXE_VERSION}: $name -> $target"
  npx --yes "@axe-core/cli@${AXE_VERSION}" "$target" \
    --tags "$WCAG_TAGS" \
    --chrome-options="no-sandbox,disable-setuid-sandbox,disable-dev-shm-usage" \
    --load-delay=250 \
    --save "$name.json" \
    --dir "$OUT_DIR" \
    --exit
}

scan home /
scan login /auth/login
scan property-catalog /property/catalog

echo "PHASE 15 WCAG 2.2 AA accessibility scan passed."
