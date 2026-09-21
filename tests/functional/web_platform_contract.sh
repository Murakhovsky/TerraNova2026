#!/usr/bin/env bash
set -euo pipefail
BASE_URL="${1:-http://127.0.0.1:8081}"
status() { curl --silent --output /dev/null --write-out '%{http_code}' "$1"; }
expect_status() {
  local url="$1" expected="$2" actual
  actual="$(status "$url")"
  [[ "$actual" == "$expected" ]] || { echo "Expected $expected for $url, got $actual" >&2; exit 1; }
}
expect_status "$BASE_URL/health/dependencies" "200"
expect_status "$BASE_URL/" "200"
expect_status "$BASE_URL/auth/login" "200"
expect_status "$BASE_URL/property/catalog" "200"
expect_status "$BASE_URL/dev/ui" "302"
expect_status "$BASE_URL/cabinet" "302"
curl --silent --head "$BASE_URL/dev/ui" | grep -qi '^location: /auth/login'
curl --silent --head "$BASE_URL/cabinet" | grep -qi '^location: /auth/login'
curl --fail --silent "$BASE_URL/health/dependencies" | grep -q '"status"'
echo "Wave 12.23 functional web contract passed."
