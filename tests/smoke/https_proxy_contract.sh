#!/usr/bin/env bash
set -Eeuo pipefail

BASE_URL="${1:-https://company-os.shop}"
BASE_URL="${BASE_URL%/}"
DOMAIN="${BASE_URL#*://}"
DOMAIN="${DOMAIN%%/*}"
HTTP_URL="http://$DOMAIN"
HTTPS_URL="https://$DOMAIN"

TMP_DIR="$(mktemp -d)"
trap 'rm -rf "$TMP_DIR"' EXIT

HTTP_HEADERS="$TMP_DIR/http-headers.txt"
HTTPS_HEADERS="$TMP_DIR/https-headers.txt"
LOGIN_HEADERS="$TMP_DIR/login-headers.txt"
BODY="$TMP_DIR/body.html"

HTTP_STATUS="$(curl --silent --show-error \
  --output /dev/null \
  --dump-header "$HTTP_HEADERS" \
  --max-redirs 0 \
  --write-out '%{http_code}' \
  "$HTTP_URL/cos")"

if [[ "$HTTP_STATUS" != "301" && "$HTTP_STATUS" != "308" ]]; then
  echo "Expected HTTP /cos to redirect permanently to HTTPS, got status $HTTP_STATUS." >&2
  cat "$HTTP_HEADERS" >&2
  exit 61
fi

HTTP_LOCATION="$(awk 'BEGIN { IGNORECASE=1 } /^Location:/ { sub(/^[^:]+:[[:space:]]*/, ""); sub(/\r$/, ""); print; exit }' "$HTTP_HEADERS")"
if [[ "$HTTP_LOCATION" != "$HTTPS_URL/cos" && "$HTTP_LOCATION" != "$HTTPS_URL/cos/" ]]; then
  echo "Unexpected HTTP redirect location: ${HTTP_LOCATION:-<missing>}" >&2
  cat "$HTTP_HEADERS" >&2
  exit 62
fi

curl --fail --silent --show-error \
  --dump-header "$HTTPS_HEADERS" \
  "$HTTPS_URL/cos" \
  --output "$BODY"

if grep -Fq "http://$DOMAIN" "$BODY"; then
  echo "HTTPS response leaks an absolute HTTP URL for $DOMAIN." >&2
  grep -Fn "http://$DOMAIN" "$BODY" >&2 || true
  exit 63
fi

if grep -Eiq '<link[^>]+rel=["'"']canonical["'"'][^>]+href=["'"']http://' "$BODY"; then
  echo "HTTPS response contains an HTTP canonical URL." >&2
  exit 64
fi

curl --fail --silent --show-error \
  --dump-header "$LOGIN_HEADERS" \
  "$HTTPS_URL/auth/login" \
  --output /dev/null

SESSION_COOKIE="$(grep -i '^set-cookie:' "$LOGIN_HEADERS" | grep -i 'PHPSESSID=' | head -n 1 || true)"
if [[ -z "$SESSION_COOKIE" ]]; then
  echo "HTTPS login response did not issue the expected PHP session cookie." >&2
  cat "$LOGIN_HEADERS" >&2
  exit 65
fi

if ! grep -Eiq ';[[:space:]]*Secure([;[:space:]]|$)' <<< "$SESSION_COOKIE"; then
  echo "HTTPS session cookie is missing the Secure attribute." >&2
  echo "$SESSION_COOKIE" >&2
  exit 66
fi

if ! grep -Eiq ';[[:space:]]*HttpOnly([;[:space:]]|$)' <<< "$SESSION_COOKIE"; then
  echo "HTTPS session cookie is missing the HttpOnly attribute." >&2
  echo "$SESSION_COOKIE" >&2
  exit 67
fi

if ! grep -Eiq ';[[:space:]]*(Expires|Max-Age)=' <<< "$SESSION_COOKIE"; then
  echo "HTTPS session cookie is browser-session-only and will be lost when the browser/computer closes." >&2
  echo "$SESSION_COOKIE" >&2
  exit 68
fi

echo "HTTPS reverse-proxy contract passed for $DOMAIN."
