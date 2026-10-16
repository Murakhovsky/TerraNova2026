#!/usr/bin/env bash
# Operator-approved staging only. Never mutates the active COS Compose project.
set -Eeuo pipefail
ROOT="$(cd "$(dirname "$0")/../.." && pwd)"
cd "$ROOT"
[[ "${1:-}" == "--inspect" || "${1:-}" == "--start" ]] || {
  echo "Usage: bash deploy/staging/guard.sh --inspect|--start" >&2; exit 64;
}
MODE="$1"
command -v docker >/dev/null || { echo "BLOCKED: Docker unavailable" >&2; exit 2; }
docker info >/dev/null 2>&1 || { echo "BLOCKED: Docker inaccessible" >&2; exit 2; }
command -v free >/dev/null || { echo "BLOCKED: cannot measure memory" >&2; exit 2; }
AVAILABLE_MIB="$(free -m | awk '/^Mem:/ {print $7}')"
DISK_MIB="$(df -Pm . | awk 'NR==2 {print $4}')"
echo "available_memory_mib: $AVAILABLE_MIB"
echo "available_disk_mib: $DISK_MIB"
# Five isolated staging services plus build peaks. Fail closed on the observed 2GB AWS host.
if (( AVAILABLE_MIB < 2048 || DISK_MIB < 12288 )); then
  echo "BLOCKED: isolated staging needs >=2048MiB free RAM and >=12288MiB free disk"
  exit 2
fi
FLEET="$(mktemp)"
trap 'rm -f "$FLEET"' EXIT
set +e
python3 bin/ops/cos_docker_fleet_audit.py --inspect-logs > "$FLEET"
FLEET_EXIT=$?
set -e
cat "$FLEET"
if (( FLEET_EXIT != 0 )); then
  echo "BLOCKED: unresolved execution authority / restart / container isolation findings"
  exit 2
fi
[[ "$MODE" == "--inspect" ]] && {
  echo "PREFLIGHT PASS: capacity and fleet; no staging changes made"
  exit 0
}
# Explicit written operator authorization, never implicitly triggered by CI.
[[ "${COS_FED_STAGING_OPERATOR_APPROVAL:-}" == "APPROVE_ISOLATED_FEDERATION_STAGING" ]] || {
  echo "BLOCKED: explicit staging operator approval required" >&2; exit 2;
}
[[ "${COS_FED_STAGING_SHA:-}" =~ ^[a-f0-9]{40}$ ]] || {
  echo "BLOCKED: pin a 40-character feature SHA" >&2; exit 2;
}
[[ "${COS_FED_STAGING_DB_PASSWORD:-}" != "" &&
   "${COS_FED_STAGING_ROOT_PASSWORD:-}" != "" &&
   "${COS_FED_STAGING_APP_SECRET:-}" != "" &&
   "${COS_FED_STAGING_SPATIAL_SECRET:-}" != "" &&
   "${COS_FED_STAGING_MERCURE_SECRET:-}" != "" ]] || {
  echo "BLOCKED: dedicated staging secrets required" >&2; exit 2;
}
# Prevent incorrect checkout/image tag, production or mutable SHA substitution.
test "$(git rev-parse HEAD)" = "$COS_FED_STAGING_SHA" || {
  echo "BLOCKED: checkout SHA does not match operator-approved SHA" >&2; exit 2;
}
docker compose -p cos-federation-staging -f deploy/staging/federation-compose.yml config --quiet
docker compose -p cos-federation-staging -f deploy/staging/federation-compose.yml up --build -d mysql redis mercure php nginx
echo "Staging containers started; migrations, tenant and Golden 50 are NOT authorized."
