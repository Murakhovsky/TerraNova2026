#!/usr/bin/env bash
# Supervised Shopify E4 pilot: only COS observations/Preview, never Shopify writes.
set -Eeuo pipefail
umask 077

usage() {
  printf '%s\n' \
    'sudo bash bin/shopify-lamps-operator.sh ORGANIZATION preflight' \
    'sudo bash bin/shopify-lamps-operator.sh ORGANIZATION provision' \
    'sudo bash bin/shopify-lamps-operator.sh ORGANIZATION pull CONNECTION_ID' \
    'sudo bash bin/shopify-lamps-operator.sh ORGANIZATION candidates CONNECTION_ID' \
    'sudo bash bin/shopify-lamps-operator.sh ORGANIZATION status CONNECTION_ID' \
    'sudo bash bin/shopify-lamps-operator.sh ORGANIZATION inspect CONNECTION_ID PRODUCT_GID' \
    'sudo bash bin/shopify-lamps-operator.sh ORGANIZATION stage CONNECTION_ID PRODUCT_GID SOURCE_HASH'
  exit 2
}
[[ "$#" -ge 2 ]] || usage
[[ "$(id -u)" == 0 ]] || { echo 'Run as root for root-owned secrets.' >&2; exit 2; }
ORGANIZATION="$1"; ACTION="$2"; shift 2
[[ "$ORGANIZATION" =~ ^[a-zA-Z0-9][a-zA-Z0-9._:-]{0,39}$ ]] || { echo 'Invalid organization ID.' >&2; exit 2; }
# Module-only health check needs no Shopify secrets or provider network.
if [[ "$ACTION" == preflight ]]; then
  [[ "$#" -eq 0 ]] || usage
  cd /opt/cos
  docker compose exec -T -e COS_SHOPIFY_OPERATOR_ENABLED=1 php \
    php bin/console cos:product:shopify preflight --organization="$ORGANIZATION"
  exit $?
fi
CONFIG=/etc/cos/shopify-lamps.env
[[ -f "$CONFIG" ]] || { echo 'Lamps secret file missing.' >&2; exit 2; }
[[ "$(stat -c '%u:%a' "$CONFIG")" == '0:600' ]] || { echo 'Lamps secret file must be root-owned with mode 600.' >&2; exit 2; }
set -a
# File contents are administrator-controlled, never supplied by remote Shopify.
source "$CONFIG"
set +a
[[ "${SHOPIFY_SHOP_DOMAIN:-}" == 'lamps-6eey3z3e.myshopify.com' ]] || { echo 'Shop domain mismatch.' >&2; exit 2; }
[[ -n "${SHOPIFY_CLIENT_ID:-}" && -n "${SHOPIFY_CLIENT_SECRET:-}" ]] || { echo 'Shopify credentials missing.' >&2; exit 2; }
PARAMS=(--organization="$ORGANIZATION")
case "$ACTION" in
  provision)
    [[ "$#" -eq 0 ]] || usage
    PARAMS+=(--shop="$SHOPIFY_SHOP_DOMAIN" --confirm=PROVISION_READ_ONLY)
    ;;
  pull|status|candidates|inspect|stage)
    [[ "$#" -ge 1 && "$1" =~ ^[a-f0-9]{32}$ ]] || usage
    PARAMS+=(--connection="$1"); shift
    case "$ACTION" in
      pull) [[ "$#" -eq 0 ]] || usage; PARAMS+=(--pages=1 --confirm=PULL_READ_ONLY) ;;
      status|candidates) [[ "$#" -eq 0 ]] || usage ;;
      inspect)
        [[ "$#" -eq 1 && "$1" =~ ^gid://shopify/Product/[0-9]+$ ]] || usage
        PARAMS+=(--product-gid="$1")
        ;;
      stage)
        [[ "$#" -eq 2 && "$1" =~ ^gid://shopify/Product/[0-9]+$ && "$2" =~ ^[a-f0-9]{64}$ ]] || usage
        PARAMS+=(--product-gid="$1" --expected-hash="$2" --confirm=STAGE_NO_WRITES)
        ;;
    esac
    ;;
  *) usage ;;
esac
cd /opt/cos
# Deliver credentials over STDIN to one short-lived PHP CLI process.
# Do not put secrets in process argv or persistent PHP/worker environment.
printf '%s\n' "$SHOPIFY_SHOP_DOMAIN" "$SHOPIFY_CLIENT_ID" "$SHOPIFY_CLIENT_SECRET" | \
    docker compose exec -T php sh -c '
        IFS= read -r SHOPIFY_SHOP_DOMAIN || exit 2
        IFS= read -r SHOPIFY_CLIENT_ID || exit 2
        IFS= read -r SHOPIFY_CLIENT_SECRET || exit 2
        export SHOPIFY_SHOP_DOMAIN SHOPIFY_CLIENT_ID SHOPIFY_CLIENT_SECRET
        export COS_SHOPIFY_OPERATOR_ENABLED=1
        exec php bin/console cos:product:shopify "$@"
    ' sh "$ACTION" "${PARAMS[@]}"
