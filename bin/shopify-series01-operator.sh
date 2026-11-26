#!/usr/bin/env bash
# COS Shopify SERIES01: isolated test-store operator, never the legacy Lamps store.
# Execute only via a supervised root shell or protected GitHub Actions job.
set -Eeuo pipefail
umask 077

usage() {
  echo "Usage: shopify-series01-operator.sh ORGANIZATION preflight|provision|status|pull|candidates [CONNECTION_ID] | order|order-sandbox CONNECTION_ID ORDER_GID | order-lifecycle-sandbox CONNECTION_ID ORDER_GID EXPECTED_SHA CODE_SHA ROUTES_SHA" >&2
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
    if [[ "$ACTION" == order-lifecycle-sandbox ]]; then
      [[ "$#" -eq 5 && "$3" =~ ^[a-f0-9]{40}$ && "$4" =~ ^[a-f0-9]{64}$ && "$5" =~ ^[a-f0-9]{64}$ ]] || usage
      EXPECTED_SHA="$3"; EXPECTED_CODE_SHA="$4"; EXPECTED_ROUTES_SHA="$5"
      EXPECTED_CONNECTION="$1"; EXPECTED_ORDER="$2"
    else
      [[ "$#" -eq 2 ]] || usage
    fi
    [[ "$1" =~ ^[a-f0-9]{32}$ && "$2" =~ ^gid://shopify/Order/[1-9][0-9]{0,18}$ ]] || usage
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

# For a lifecycle evidence run, pin the actual live container and verify the
# deployment manifest, host PHP/DI/routes and active CLI code both before and
# after the provider read. A replaced container or deployment drift is not PASS.
if [[ "$ACTION" == order-lifecycle-sandbox ]]; then
  CONTAINER_NAME="$CONTAINER"
  CONTAINER="$(docker inspect --format '{{.Id}}' "$CONTAINER_NAME")"
  [[ "$CONTAINER" =~ ^[a-f0-9]{64}$ ]] || exit 3
  verify_lifecycle_deployment() {
    local host_code host_routes container_code container_routes
    [[ "$(docker inspect --format '{{.Id}} {{.State.Running}}' "$CONTAINER_NAME")" == "$CONTAINER true" ]] || {
      echo 'SERIES01_LIFECYCLE_CONTAINER_CHANGED' >&2; return 3;
    }
    python3 - "$EXPECTED_SHA" "$EXPECTED_CODE_SHA" "$EXPECTED_ROUTES_SHA" <<'PYDEPLOY'
import json, sys
with open('/opt/cos/.cos-deployment-attestation.json', encoding='utf-8') as handle:
    attestation = json.load(handle)
if [attestation.get(key) for key in ('source_sha', 'execution_source_sha256', 'routes_sha256')] != sys.argv[1:]:
    raise SystemExit('SERIES01_LIFECYCLE_DEPLOYED_ATTESTATION_MISMATCH')
PYDEPLOY
    host_code="$(cd /opt/cos && {
      find app/Kernel app/Platform app/Domains app/Infrastructure symfony/src -type f -name '*.php' -print | LC_ALL=C sort | xargs sha256sum
      sha256sum symfony/config/services.yaml
    } | sha256sum | cut -d' ' -f1)"
    host_routes="$(sha256sum /opt/cos/symfony/config/routes.yaml | cut -d' ' -f1)"
    container_code="$(docker exec "$CONTAINER" sh -c 'cd /var/www/html && {
      find app/Kernel app/Platform app/Domains app/Infrastructure symfony/src -type f -name "*.php" -print | LC_ALL=C sort | xargs sha256sum
      sha256sum symfony/config/services.yaml
    } | sha256sum | cut -d" " -f1')"
    container_routes="$(docker exec "$CONTAINER" sha256sum /var/www/html/symfony/config/routes.yaml | cut -d' ' -f1)"
    [[ "$host_code" == "$EXPECTED_CODE_SHA" && "$container_code" == "$EXPECTED_CODE_SHA" &&
       "$host_routes" == "$EXPECTED_ROUTES_SHA" && "$container_routes" == "$EXPECTED_ROUTES_SHA" ]] || {
      echo 'SERIES01_LIFECYCLE_DEPLOYED_CODE_MISMATCH' >&2; return 3;
    }
  }
  verify_lifecycle_deployment
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
run_read_operator() {
  printf '%s\n' "$SHOPIFY_SHOP_DOMAIN" "$SHOPIFY_CLIENT_ID" "$SHOPIFY_CLIENT_SECRET" | \
  docker exec -i "$CONTAINER" sh -c '
    IFS= read -r SHOPIFY_SHOP_DOMAIN || exit 3
    IFS= read -r SHOPIFY_CLIENT_ID || exit 3
    IFS= read -r SHOPIFY_CLIENT_SECRET || exit 3
    export SHOPIFY_SHOP_DOMAIN SHOPIFY_CLIENT_ID SHOPIFY_CLIENT_SECRET
    export COS_SHOPIFY_OPERATOR_ENABLED=1
    exec php bin/console cos:product:shopify "$@"
  ' sh "$ACTION" "${ARGS[@]}"
}
if [[ "$ACTION" == order-lifecycle-sandbox ]]; then
  RESULT="$(run_read_operator)"
  unset SHOPIFY_CLIENT_ID SHOPIFY_CLIENT_SECRET
  verify_lifecycle_deployment
  # Publish evidence only after both deployment checks. The operator's result
  # remains a provider observation requiring independent review, not D3 PASS.
  printf '%s\n' "$RESULT" | python3 -c '
import json, sys
report = json.load(sys.stdin)
if (report.get("status") != "SANDBOX_LIFECYCLE_READBACK_VERIFIED"
        or report.get("scenario_acceptance") != "independent_review_required"
        or [report.get(key) for key in ("organization_id", "connection_id", "order_gid")] != sys.argv[4:]
        or report.get("test_order") is not True
        or report.get("inventory_authority") != "external_review"
        or any(report.get(key) is not False for key in ("native_order_created", "local_stock_mutation", "payment_settled", "provider_mutation", "ownership_changed", "physical_commitment_verified"))):
    raise SystemExit("SERIES01_LIFECYCLE_RESULT_MISMATCH")
report["deployment_attestation"] = {"source_sha":sys.argv[1],"execution_source_sha256":sys.argv[2],"routes_sha256":sys.argv[3],"cli_source_verified_before_and_after":True}
print(json.dumps(report, sort_keys=True))
' "$EXPECTED_SHA" "$EXPECTED_CODE_SHA" "$EXPECTED_ROUTES_SHA" "$ORG" "$EXPECTED_CONNECTION" "$EXPECTED_ORDER"
else
  run_read_operator
  unset SHOPIFY_CLIENT_ID SHOPIFY_CLIENT_SECRET
fi
