#!/usr/bin/env bash
set -euo pipefail

BASE_URL="${1:-https://company-os.shop}"
BASE_URL="${BASE_URL%/}"
TMP_DIR="$(mktemp -d)"
trap 'rm -rf "$TMP_DIR"' EXIT

BODY_FILE="$TMP_DIR/body"
HEADERS_FILE="$TMP_DIR/headers"

request() {
  local path="$1"
  curl --silent --show-error \
    --retry 2 --retry-delay 1 --retry-connrefused \
    --output "$BODY_FILE" \
    --dump-header "$HEADERS_FILE" \
    --write-out '%{http_code}' \
    "${BASE_URL}${path}"
}

fail_request() {
  local message="$1"
  printf 'WEB V0.13 live routing smoke failed: %s\n' "$message" >&2
  printf '%s\n' '--- response headers ---' >&2
  cat "$HEADERS_FILE" >&2 || true
  printf '%s\n' '--- response body ---' >&2
  head -c 2000 "$BODY_FILE" >&2 || true
  printf '\n' >&2
  exit 1
}

expect_status() {
  local path="$1"
  shift
  local code
  code="$(request "$path")"

  local expected
  for expected in "$@"; do
    if [[ "$code" == "$expected" ]]; then
      printf 'PASS %s -> %s\n' "$path" "$code"
      return 0
    fi
  done

  fail_request "$path returned $code; expected one of: $*"
}

expect_login_redirect() {
  local path="$1"
  local code
  code="$(request "$path")"
  case "$code" in
    301|302|303|307|308) ;;
    *) fail_request "$path returned $code; expected redirect to auth/login" ;;
  esac

  local location
  location="$(grep -i '^location:' "$HEADERS_FILE" | tail -n 1 | sed -E 's/^[^:]+:[[:space:]]*//; s/\r$//' || true)"
  if [[ "$location" != *"auth/login"* ]]; then
    fail_request "$path redirected to '$location'; expected auth/login"
  fi

  printf 'PASS %s -> %s %s\n' "$path" "$code" "$location"
}

expect_json_404() {
  local path="$1"
  local code
  code="$(request "$path")"
  if [[ "$code" != "404" ]]; then
    fail_request "$path returned $code; expected JSON 404"
  fi
  if ! grep -qi '^content-type:.*application/json' "$HEADERS_FILE"; then
    fail_request "$path did not return application/json"
  fi
  if ! grep -Eq '"error"[[:space:]]*:[[:space:]]*"not_found"' "$BODY_FILE"; then
    fail_request "$path JSON body is missing error=not_found"
  fi

  printf 'PASS %s -> JSON 404\n' "$path"
}

expect_status '/' 200
expect_status '/auth/login' 200

expect_login_redirect '/cabinet'
expect_login_redirect '/admin'
expect_login_redirect '/spatial/manage'

expect_status '/cabinet/index' 404
expect_status '/admin/index' 404
expect_status '/this-route-does-not-exist-v013' 404
expect_status '/cabinet/telegramConnect' 404
expect_status '/spatial/save' 404

expect_json_404 '/api/this-route-does-not-exist-v013'

printf 'WEB V0.13 live routing smoke passed.\n'
