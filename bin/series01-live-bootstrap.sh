#!/usr/bin/env bash
# Supervised, one-shot S1/S2 live setup AFTER an exact, successful AWS dev deploy.
# No approved import execution occurs here; only immutable preview staging.
set -Eeuo pipefail
umask 077
[[ "$(id -u)" == 0 && "$#" -eq 1 && "$1" =~ ^[a-f0-9]{40}$ ]] || {
  echo SERIES01_INVALID_DEPLOY_ATTESTATION >&2; exit 2
}
EXPECTED_SHA="$1"
cd /opt/cos
test -s .cos-deployment-attestation.json || { echo SERIES01_ATTESTATION_MISSING >&2; exit 3; }
python3 - "$EXPECTED_SHA" <<'PY'
import json,sys
with open('.cos-deployment-attestation.json',encoding='utf-8') as f:
    attestation=json.load(f)
if attestation.get('source_sha')!=sys.argv[1]:
    raise SystemExit('SERIES01_DEPLOYED_SHA_MISMATCH')
print('SERIES01_DEPLOYED_SOURCE_VERIFIED='+sys.argv[1][:12])
PY
docker inspect cos-php-1 >/dev/null || { echo COS_PHP_CONTAINER_MISSING >&2; exit 4; }
MARKER_DIR=/var/lib/cos
MARKER="$MARKER_DIR/series01-tenant-default-bootstrap.done"
OPERATOR=(docker exec -e COS_PRODUCT_SERIES01_OPERATOR_ENABLED=1 cos-php-1 \
  php bin/console cos:product:series01)
if [[ -f "$MARKER" ]]; then
  echo SERIES01_ALREADY_BOOTSTRAPPED
  # A completed bootstrap is never repeated; status inspection is read-only.
  "${OPERATOR[@]}" audit --organization=default
  exit 0
fi
"${OPERATOR[@]}" status --organization=default
"${OPERATOR[@]}" enable --organization=default --confirm=ENABLE_PRODUCT_MANAGEMENT_DEFAULT
"${OPERATOR[@]}" status --organization=default

CONN_JSON=$(bash /opt/cos/bin/shopify-series01-operator.sh default provision)
printf '%s\n' "$CONN_JSON"
CONN=$(printf '%s' "$CONN_JSON" | python3 -c '
import json,sys,re
x=json.load(sys.stdin)
assert x.get("status")=="PROVISIONED_READ_ONLY"
v=x.get("connection_id","")
assert re.fullmatch("[a-f0-9]{32}",v)
print(v)
')
bash /opt/cos/bin/shopify-series01-operator.sh default status "$CONN"
bash /opt/cos/bin/shopify-series01-operator.sh default pull "$CONN"

PREVIEW_JSON=$(docker exec -i -e COS_PRODUCT_SERIES01_OPERATOR_ENABLED=1 cos-php-1 \
  php bin/console cos:product:series01 preview --organization=default --confirm=STAGE_SERIES01_PREVIEW_ONLY)
printf '%s\n' "$PREVIEW_JSON"
printf '%s' "$PREVIEW_JSON" | python3 -c '
import json,sys,re
x=json.load(sys.stdin)
assert x.get("status")=="PREVIEW_STAGED_AWAITING_INDEPENDENT_APPROVAL"
assert x.get("organization_id")=="default"
assert x.get("counts",{}).get("ready")==9
assert re.fullmatch("[a-f0-9]{32}",x.get("batch_id",""))
assert x.get("canonical_products_written")==0
'
install -d -m 700 "$MARKER_DIR"
printf 'deployed_sha=%s\nconnection_id=%s\n' "$EXPECTED_SHA" "$CONN" > "$MARKER"
chmod 600 "$MARKER"
echo SERIES01_TENANT_CONNECTION_AND_PREVIEW_READY
echo SERIES01_AWAITING_INDEPENDENT_COS_PLAN_AND_ACTION_APPROVAL
"${OPERATOR[@]}" audit --organization=default
