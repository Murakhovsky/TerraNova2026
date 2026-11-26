#!/usr/bin/env bash
# COS Shopify SERIES01: isolated test-store operator, never the legacy Lamps store.
# Execute only via a supervised root shell or protected GitHub Actions job.
set -Eeuo pipefail
umask 077

usage() {
  echo "Usage: shopify-series01-operator.sh ORGANIZATION preflight|provision|status|pull|candidates [CONNECTION_ID] | order|order-sandbox|order-lifecycle-sandbox CONNECTION_ID ORDER_GID" >&2
  exit 2
}
[[ "$(id -u)" == 0 && "$#" -ge 2 ]] || usage
ORG="$1"; ACTION="$2"; shift 2
[[ "$ORG" =~ ^[a-zA-Z0-9][a-zA-Z0-9._:-]{0,39}$ ]] || usage
[[ "$ACTION" =~ ^(preflight|provision|status|pull|candidates|order|order-sandbox|order-lifecycle-sandbox)$ ]] || usage

CONTAINER='cos-php-1'
docker inspect "$CONTAINER" >/dev/null || { echo 'COS_PHP_CONTAINER_MISSING' >&2; exit 3; }
ARGS=("--organization=$ORG")
case "$ACTION" in
  preflight|provision)
    [[ "$#" -eq 0 ]] || usage
    if [[ "$ACTION" == provision ]]; then
      ARGS+=("--shop=e2rnbw-mr.myshopify.com" "--secret-ref=shopify_env_primary" "--confirm=PROVISION_READ_ONLY")
    fi ;;
  status|pull|candidates)
    [[ "$#" -eq 1 && "$1" =~ ^[a-f0-9]{32}$ ]] || usage
    ARGS+=("--connection=$1")
    if [[ "$ACTION" == pull ]]; then ARGS+=("--pages=1" "--confirm=PULL_READ_ONLY"); fi
    if [[ "$ACTION" == candidates ]]; then ARGS+=("--limit=20"); fi ;;
  order|order-sandbox|order-lifecycle-sandbox)
    [[ "$#" -eq 2 && "$1" =~ ^[a-f0-9]{32}$ && "$2" =~ ^gid://shopify/Order/[1-9][0-9]{0,18}$ ]] || usage
    ARGS+=("--connection=$1" "--order-gid=$2")
    if [[ "$ACTION" == order-sandbox ]]; then ARGS+=("--confirm=VERIFY_TEST_ORDER_READ_ONLY"); fi
    if [[ "$ACTION" == order-lifecycle-sandbox ]]; then ARGS+=("--confirm=VERIFY_TEST_LIFECYCLE_READ_ONLY"); fi ;;
esac

# Preflight performs no Shopify calls, no secret reads, no DB changes.
if [[ "$ACTION" == preflight ]]; then
  docker exec -e COS_SHOPIFY_OPERATOR_ENABLED=1 "$CONTAINER" \
    php bin/console cos:product:shopify preflight "${ARGS[@]}"
  exit $?
fi

CONFIG=/etc/cos/shopify.env
[[ -f "$CONFIG" && ! -L "$CONFIG" ]] || { echo 'SERIES01_CONFIG_MISSING' >&2; exit 3; }
[[ "$(stat -c '%u:%a' "$CONFIG")" == "0:600" ]] || { echo 'SERIES01_CONFIG_OWNER_OR_MODE_INVALID' >&2; exit 3; }
set -a
# Only a root-owned file is sourced, never caller-supplied content.
source "$CONFIG"
set +a
[[ "${SHOPIFY_SHOP_DOMAIN:-}" == 'e2rnbw-mr.myshopify.com' ]] || { echo 'SERIES01_SHOP_MISMATCH' >&2; exit 3; }
[[ -n "${SHOPIFY_CLIENT_ID:-}" && -n "${SHOPIFY_CLIENT_SECRET:-}" ]] || { echo 'SERIES01_CREDENTIALS_MISSING' >&2; exit 3; }
[[ "$SHOPIFY_CLIENT_ID" != *$'\n'* && "$SHOPIFY_CLIENT_SECRET" != *$'\n'* ]] || exit 3
# Credentials flow through STDIN into the *one-off* CLI process only.
# No process argv, GitHub Secret, PHP worker env, provider payload or logs.
printf '%s\n' "$SHOPIFY_SHOP_DOMAIN" "$SHOPIFY_CLIENT_ID" "$SHOPIFY_CLIENT_SECRET" | \
  docker exec -i "$CONTAINER" sh -c '
    IFS= read -r SHOPIFY_SHOP_DOMAIN || exit 3
    IFS= read -r SHOPIFY_CLIENT_ID || exit 3
    IFS= read -r SHOPIFY_CLIENT_SECRET || exit 3
    export SHOPIFY_SHOP_DOMAIN SHOPIFY_CLIENT_ID SHOPIFY_CLIENT_SECRET
    export COS_SHOPIFY_OPERATOR_ENABLED=1
    exec php bin/console cos:product:shopify "$@"
  ' sh "$ACTION" "${ARGS[@]}"
unset SHOPIFY_CLIENT_ID SHOPIFY_CLIENT_SECRET
