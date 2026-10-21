#!/usr/bin/env bash
set -Eeuo pipefail

PROJECT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$PROJECT_DIR"

if ! command -v docker >/dev/null 2>&1; then
  echo "Docker is not installed on the server." >&2
  exit 20
fi

if docker info >/dev/null 2>&1; then
  DOCKER=(docker)
elif command -v sudo >/dev/null 2>&1 && sudo -n docker info >/dev/null 2>&1; then
  # sudo resets untrusted environment variables by default. Restore ONLY
  # validated image overrides for the root-owned Docker Compose process.
  DOCKER=(sudo -n env)
  if [[ -n "${COS_PHP_IMAGE:-}" ]]; then
    DOCKER+=("COS_PHP_IMAGE=$COS_PHP_IMAGE")
  fi
  if [[ -n "${COS_NGINX_IMAGE:-}" ]]; then
    DOCKER+=("COS_NGINX_IMAGE=$COS_NGINX_IMAGE")
  fi
  DOCKER+=(docker)
else
  echo "Deploy user cannot access Docker." >&2
  exit 21
fi

if [[ -f .env ]]; then
  ENV_FILE=".env"
elif [[ -f .env.docker ]]; then
  ENV_FILE=".env.docker"
else
  echo "Neither .env nor .env.docker exists." >&2
  exit 23
fi

# Concurrent main deployments must not interfere with first-run database migrations.
if [[ -f .cos-fresh-db-bootstrap-in-progress && "${COS_NEW_DB_BOOTSTRAP:-0}" != "1" ]]; then
  echo "Fresh database bootstrap is not complete; refusing concurrent deployment." >&2
  exit 55
fi

COMPOSE=("${DOCKER[@]}" compose --env-file "$ENV_FILE")

generate_secret() {
  if command -v openssl >/dev/null 2>&1; then
    openssl rand -hex 32
    return
  fi
  head -c 32 /dev/urandom | od -An -tx1 | tr -d ' \n'
}

ensure_secret() {
  local key="$1"
  local current
  current="$(sed -n "s/^${key}=//p" "$ENV_FILE" | tail -n 1)"
  if [[ -n "$current" ]]; then
    return
  fi
  # Never silently replace encryption/runtime keys on a restored production host.
  if [[ "${COS_DEPLOY_MODE:-build}" == registry ]]; then
    echo "Missing required $key in existing runtime env; refusing to regenerate production credentials." >&2
    exit 54
  fi

  local value
  value="$(generate_secret)"
  if grep -q "^${key}=" "$ENV_FILE"; then
    sed -i "s/^${key}=.*$/${key}=${value}/" "$ENV_FILE"
  else
    printf '\n%s=%s\n' "$key" "$value" >> "$ENV_FILE"
  fi
  chmod 600 "$ENV_FILE" 2>/dev/null || true
  echo "Initialized missing runtime secret: $key"
}

wait_for_healthy_service() {
  local service="$1"
  local attempts="${2:-30}"
  local container_id=""

  for attempt in $(seq 1 "$attempts"); do
    container_id="$("${COMPOSE[@]}" ps -q "$service" 2>/dev/null || true)"
    if [[ -n "$container_id" ]] && [[ "$("${DOCKER[@]}" inspect -f '{{.State.Health.Status}}' "$container_id" 2>/dev/null || true)" == "healthy" ]]; then
      return 0
    fi
    sleep 2
  done

  return 1
}

ensure_secret SYMFONY_APP_SECRET
ensure_secret SPATIAL_JWT_SECRET
ensure_secret MERCURE_JWT_SECRET
ensure_secret COS_SECRET_MASTER_KEY

engineering_repo="$(sed -n 's/^COS_ENGINEERING_GITHUB_REPOSITORY=//p' "$ENV_FILE" | tail -n 1)"
engineering_token="$(sed -n 's/^COS_ENGINEERING_GITHUB_TOKEN=//p' "$ENV_FILE" | tail -n 1)"
if [[ -z "$engineering_repo" || -z "$engineering_token" ]]; then
  echo "WARNING: Engineering GitHub repository gateway is not configured in $ENV_FILE." >&2
  echo "Principal Architect / Developer / Reviewer stages that require authoritative GitHub access will stop as STALLED." >&2
fi

# SHA-pinned GHCR images for VPS; the legacy local build mode remains available.
COS_DEPLOY_MODE="${COS_DEPLOY_MODE:-build}"
COS_DEPLOY_TIER="${COS_DEPLOY_TIER:-full}"
case "$COS_DEPLOY_MODE" in
  build) ;;
  registry)
    if [[ ! "${COS_PHP_IMAGE:-}" =~ ^ghcr[.]io/murakhovsky/terranova2026-php:([0-9a-f]{40})$ ]]; then
      echo "COS_PHP_IMAGE must be a commit-SHA-pinned GHCR reference." >&2; exit 52
    fi
    PHP_IMAGE_SHA="${BASH_REMATCH[1]}"
    if [[ ! "${COS_NGINX_IMAGE:-}" =~ ^ghcr[.]io/murakhovsky/terranova2026-nginx:([0-9a-f]{40})$ ]]; then
      echo "COS_NGINX_IMAGE must be a commit-SHA-pinned GHCR reference." >&2; exit 52
    fi
    if [[ "$PHP_IMAGE_SHA" != "${BASH_REMATCH[1]}" ]]; then
      echo "PHP and Nginx image SHAs must match." >&2; exit 52
    fi
    ;;
  *) echo "Unsupported COS_DEPLOY_MODE: $COS_DEPLOY_MODE" >&2; exit 53 ;;
esac
case "$COS_DEPLOY_TIER" in
  core|full) ;;
  *) echo "Unsupported COS_DEPLOY_TIER: $COS_DEPLOY_TIER" >&2; exit 53 ;;
esac

"${COMPOSE[@]}" config --quiet
if [[ "$COS_DEPLOY_MODE" == registry ]]; then
  # Ensure sudo did not silently revert Compose image substitutions to local tags.
  if ! "${COMPOSE[@]}" config --images | grep -Fxq -- "$COS_PHP_IMAGE"; then
    echo "Registry PHP image was not resolved by Docker Compose." >&2; exit 56
  fi
  if ! "${COMPOSE[@]}" config --images | grep -Fxq -- "$COS_NGINX_IMAGE"; then
    echo "Registry Nginx image was not resolved by Docker Compose." >&2; exit 56
  fi
  echo "Pulling immutable COS images, not building on this VPS."
  "${COMPOSE[@]}" pull php nginx
else
  "${COMPOSE[@]}" build --pull php nginx
fi

# Port 8081 belongs exclusively to the canonical COS HTTP runtime.
COS_HTTP_PORT="${COS_HTTP_PORT:-8081}"
while IFS= read -r container_id; do
  [[ -n "$container_id" ]] || continue
  container_name="$("${DOCKER[@]}" inspect -f '{{.Name}}' "$container_id" 2>/dev/null | sed 's#^/##')"
  compose_project="$("${DOCKER[@]}" inspect -f '{{ index .Config.Labels "com.docker.compose.project" }}' "$container_id" 2>/dev/null || true)"
  compose_service="$("${DOCKER[@]}" inspect -f '{{ index .Config.Labels "com.docker.compose.service" }}' "$container_id" 2>/dev/null || true)"

  if [[ "$compose_project" == "cos" && "$compose_service" == "nginx" ]]; then
    continue
  fi

  echo "Removing stale Docker owner of 127.0.0.1:$COS_HTTP_PORT: ${container_name:-$container_id} (project=${compose_project:-unknown}, service=${compose_service:-unknown})"
  "${DOCKER[@]}" rm -f "$container_id"
done < <("${DOCKER[@]}" ps --filter "publish=$COS_HTTP_PORT" --format '{{.ID}}')

# Preserve the current public socket while application containers are replaced.
# The nginx config uses Docker DNS with dynamically resolved PHP/Mercure upstreams.
NGINX_ID="$("${COMPOSE[@]}" ps -q nginx 2>/dev/null || true)"
NGINX_HOT_RELOAD=0
if [[ -n "$NGINX_ID" ]] && [[ "$("${DOCKER[@]}" inspect -f '{{.State.Running}}' "$NGINX_ID" 2>/dev/null || true)" == "true" ]]; then
  echo "Hot-reloading canonical nginx without dropping 127.0.0.1:$COS_HTTP_PORT."

  "${DOCKER[@]}" exec "$NGINX_ID" cp /etc/nginx/conf.d/default.conf /tmp/default.conf.deploy-backup
  "${DOCKER[@]}" cp docker/symfony/nginx/default.conf "$NGINX_ID":/etc/nginx/conf.d/default.conf

  if ! "${DOCKER[@]}" exec "$NGINX_ID" nginx -t; then
    "${DOCKER[@]}" exec "$NGINX_ID" cp /tmp/default.conf.deploy-backup /etc/nginx/conf.d/default.conf || true
    echo "Canonical nginx hot-reload configuration validation failed." >&2
    exit 33
  fi

  if [[ -d public/build ]]; then
    "${DOCKER[@]}" exec "$NGINX_ID" mkdir -p /var/www/html/symfony/public/build
    "${DOCKER[@]}" cp public/build/. "$NGINX_ID":/var/www/html/symfony/public/build/
  fi

  "${DOCKER[@]}" exec "$NGINX_ID" nginx -s reload
  NGINX_HOT_RELOAD=1
fi

"${COMPOSE[@]}" up -d mysql redis mercure

for attempt in $(seq 1 30); do
  if "${COMPOSE[@]}" exec -T mysql sh -c 'mysqladmin ping -h 127.0.0.1 -u"$MYSQL_USER" -p"$MYSQL_PASSWORD" --silent' >/dev/null 2>&1; then
    break
  fi
  sleep 2
done

if ! wait_for_healthy_service mercure 30; then
  echo "Mercure failed readiness before canonical HTTP startup." >&2
  "${COMPOSE[@]}" logs --no-color --tail=200 mercure >&2 || true
  exit 32
fi

"${COMPOSE[@]}" run --rm --no-deps php php bin/console cos:schema:migrate
"${COMPOSE[@]}" run --rm --no-deps php php bin/console doctrine:migrations:migrate --no-interaction --allow-no-migration

# Capital Markets stays disabled by default across tenants. The canonical AWS dev
# organization is explicitly activated through the audited module lifecycle.
CAPITAL_MARKETS_ACTOR="$("${COMPOSE[@]}" exec -T mysql sh -c 'mysql -N -B -u"$MYSQL_USER" -p"$MYSQL_PASSWORD" "$MYSQL_DATABASE" -e "SELECT id FROM tn_users WHERE organization_id=\"default\" AND status=\"active\" ORDER BY (role=\"admin\") DESC,id LIMIT 1"' | tr -d '\r' | tail -n 1)"
if [[ "$CAPITAL_MARKETS_ACTOR" =~ ^[1-9][0-9]*$ ]]; then
  "${COMPOSE[@]}" run --rm --no-deps php php bin/console cos:capital-markets:cutover enable \
    --organization=default \
    --actor="$CAPITAL_MARKETS_ACTOR" \
    --confirm=ENABLE_CAPITAL_MARKETS \
    --reason="AWS dev deployment activation"
else
  echo "WARNING: Capital Markets was not activated because no active default-organization actor exists." >&2
fi

# Replace application and worker containers, but deliberately leave nginx alive.
if [[ "$COS_DEPLOY_TIER" == core ]]; then
  # Minimal website, queue, persistence and realtime. Specialist workers are disabled.
  RUNTIME_SERVICES=(php worker)
  DEFERRED_SERVICES=(engineering-worker engineering-immediate-worker kernel-worker spatial-worker integration-worker telegram-worker scheduler)
  "${COMPOSE[@]}" stop --timeout 30 "${DEFERRED_SERVICES[@]}"
else
  mapfile -t RUNTIME_SERVICES < <("${COMPOSE[@]}" config --services | grep -Ev '^(mysql|redis|mercure|nginx)$')
fi
if (( ${#RUNTIME_SERVICES[@]} > 0 )); then
  "${COMPOSE[@]}" up -d --no-build --no-deps --remove-orphans "${RUNTIME_SERVICES[@]}"
fi

if ! wait_for_healthy_service php 30; then
  echo "Symfony PHP failed readiness during rolling deployment." >&2
  "${COMPOSE[@]}" logs --no-color --tail=200 php >&2 || true
  exit 34
fi

if [[ "$NGINX_HOT_RELOAD" -ne 1 ]]; then
  echo "No running canonical nginx found; starting a fresh HTTP runtime."
  "${COMPOSE[@]}" up -d --no-build --no-deps nginx
fi

for attempt in $(seq 1 30); do
  if curl --fail --silent --show-error http://127.0.0.1:8081/health/dependencies >/tmp/cos-health.json; then
    break
  fi
  sleep 2
done

if ! curl --fail --silent --show-error http://127.0.0.1:8081/health/dependencies >/tmp/cos-health.json; then
  cat /tmp/cos-health.json >&2 2>/dev/null || true
  "${COMPOSE[@]}" logs --no-color --tail=250 nginx php mercure mysql redis >&2 || true
  exit 31
fi

if ! "${DOCKER[@]}" exec cos-php-1 php bin/console cos:architecture:smoke; then
  echo "Architecture Graph runtime smoke failed." >&2
  exit 30
fi

"${COMPOSE[@]}" ps
printf 'COS Symfony rolling deployment completed successfully.\n'
