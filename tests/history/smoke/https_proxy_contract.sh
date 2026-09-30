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
AUTH_HEADERS="$TMP_DIR/auth-headers.txt"
BODY="$TMP_DIR/body.html"

HTTP_STATUS="$(curl --silent --show-error \
  --output /dev/null \
  --dump-header "$HTTP_HEADERS" \
  --max-redirs 0 \
  --write-out '%{http_code}' \
  "$HTTP_URL/")"

if [[ "$HTTP_STATUS" != "301" && "$HTTP_STATUS" != "308" ]]; then
  echo "Expected HTTP / to redirect permanently to HTTPS, got status $HTTP_STATUS." >&2
  cat "$HTTP_HEADERS" >&2
  exit 61
fi

HTTP_LOCATION="$(awk 'BEGIN { IGNORECASE=1 } /^Location:/ { sub(/^[^:]+:[[:space:]]*/, ""); sub(/\r$/, ""); print; exit }' "$HTTP_HEADERS")"
if [[ "$HTTP_LOCATION" != "$HTTPS_URL" && "$HTTP_LOCATION" != "$HTTPS_URL/" ]]; then
  echo "Unexpected HTTP redirect location: ${HTTP_LOCATION:-<missing>}" >&2
  cat "$HTTP_HEADERS" >&2
  exit 62
fi

curl --fail --silent --show-error \
  --dump-header "$HTTPS_HEADERS" \
  "$HTTPS_URL/" \
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

DEPENDENCY_HEALTH_BODY="$TMP_DIR/symfony-health.json"
curl --fail --silent --show-error \
  "$HTTPS_URL/health/dependencies" \
  --output "$DEPENDENCY_HEALTH_BODY"

if ! grep -Eq '"status"[[:space:]]*:[[:space:]]*"ok"' "$DEPENDENCY_HEALTH_BODY"; then
  echo "Public /health/dependencies is not served by the healthy Symfony runtime." >&2
  cat "$DEPENDENCY_HEALTH_BODY" >&2 || true
  exit 69
fi


COS_HEADERS="$TMP_DIR/cos-headers.txt"
COS_STATUS="$(curl --silent --show-error \
  --output /dev/null \
  --dump-header "$COS_HEADERS" \
  --max-redirs 0 \
  --write-out '%{http_code}' \
  "$HTTPS_URL/cos/control-center")"

if [[ "$COS_STATUS" != "302" && "$COS_STATUS" != "303" ]]; then
  echo "Expected unauthenticated /cos/control-center to resolve and redirect to native login, got status $COS_STATUS." >&2
  cat "$COS_HEADERS" >&2
  exit 72
fi

COS_LOCATION="$(awk 'BEGIN { IGNORECASE=1 } /^Location:/ { sub(/^[^:]+:[[:space:]]*/, ""); sub(/\r$/, ""); print; exit }' "$COS_HEADERS")"
if [[ "$COS_LOCATION" != "/auth/login" && "$COS_LOCATION" != "$HTTPS_URL/auth/login" ]]; then
  echo "Unexpected COS Control Center authentication redirect: ${COS_LOCATION:-<missing>}." >&2
  cat "$COS_HEADERS" >&2
  exit 73
fi

AUTH_STATUS="$(curl --silent --show-error \
  --output /dev/null \
  --dump-header "$AUTH_HEADERS" \
  --max-redirs 0 \
  --write-out '%{http_code}' \
  "$HTTPS_URL/sales")"

if [[ "$AUTH_STATUS" != "302" && "$AUTH_STATUS" != "303" ]]; then
  echo "Expected unauthenticated /sales to redirect to native login, got status $AUTH_STATUS." >&2
  cat "$AUTH_HEADERS" >&2
  exit 65
fi

AUTH_LOCATION="$(awk 'BEGIN { IGNORECASE=1 } /^Location:/ { sub(/^[^:]+:[[:space:]]*/, ""); sub(/\r$/, ""); print; exit }' "$AUTH_HEADERS")"
if [[ "$AUTH_LOCATION" != "/auth/login" && "$AUTH_LOCATION" != "$HTTPS_URL/auth/login" ]]; then
  echo "Unexpected authentication redirect: ${AUTH_LOCATION:-<missing>}." >&2
  cat "$AUTH_HEADERS" >&2
  exit 70
fi

LOGIN_HEADERS="$TMP_DIR/login-headers.txt"
LOGIN_BODY="$TMP_DIR/login-body.html"
LOGIN_STATUS="$(curl --silent --show-error \
  --output "$LOGIN_BODY" \
  --dump-header "$LOGIN_HEADERS" \
  --write-out '%{http_code}' \
  "$HTTPS_URL/auth/login")"

if [[ "$LOGIN_STATUS" != "200" ]]; then
  echo "Expected native Symfony login page to return 200, got status $LOGIN_STATUS." >&2
  cat "$LOGIN_HEADERS" >&2
  exit 71
fi

if ! grep -Fq 'Вхід' "$LOGIN_BODY"; then
  echo "Native Symfony login page did not render the expected login surface." >&2
  exit 74
fi

echo "HTTPS reverse-proxy, protected-route and native login contract passed for $DOMAIN."
