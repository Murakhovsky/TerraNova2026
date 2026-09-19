#!/usr/bin/env bash
set -Eeuo pipefail

PROJECT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$PROJECT_DIR"

if ! command -v docker >/dev/null 2>&1; then
  echo "Docker is not installed on the dev server." >&2
  exit 20
fi

# Prefer direct Docker access. On standard Ubuntu/AWS setups the deploy user may
# have passwordless sudo but not be a member of the docker group yet.
if docker info >/dev/null 2>&1; then
  DOCKER=(docker)
elif command -v sudo >/dev/null 2>&1 && sudo -n docker info >/dev/null 2>&1; then
  DOCKER=(sudo -n docker)
  echo "Docker socket is not directly accessible; using passwordless sudo."
else
  echo "Deploy user cannot access Docker. Add the user to the docker group or allow passwordless sudo for Docker." >&2
  exit 21
fi

if ! "${DOCKER[@]}" compose version >/dev/null 2>&1; then
  echo "Docker Compose v2 is not available on the dev server." >&2
  exit 22
fi

if [[ -f .env ]]; then
  ENV_FILE=".env"
  COMPOSE=("${DOCKER[@]}" compose)
elif [[ -f .env.docker ]]; then
  ENV_FILE=".env.docker"
  COMPOSE=("${DOCKER[@]}" compose --env-file .env.docker)
else
  echo "Neither .env nor .env.docker exists in $PROJECT_DIR." >&2
  echo "Create the server environment file before deploying." >&2
  exit 23
fi

structured_log() {
  local container_id="$1"
  local label="$2"
  local tmp_log
  [[ -n "$container_id" ]] || return 0

  tmp_log="$(mktemp)"
  if "${DOCKER[@]}" cp "$container_id:/var/www/html/tmp/logs/cos.jsonl" "$tmp_log" >/dev/null 2>&1; then
    echo "Structured $label log:" >&2
    tail -n 100 "$tmp_log" >&2 || true
  fi
  rm -f "$tmp_log"
}

migration_structured_log() {
  local migrate_id
  migrate_id="$("${COMPOSE[@]}" ps -aq migrate 2>/dev/null || true)"
  structured_log "$migrate_id" "migration"
}

worker_structured_log() {
  local worker_id
  worker_id="$("${COMPOSE[@]}" ps -aq worker 2>/dev/null || true)"
  structured_log "$worker_id" "worker"
}

echo "Using server environment: $ENV_FILE"
"${COMPOSE[@]}" config --quiet

# Build application images from the just-synced COS revision. The MySQL volume
# is persistent and is never replaced by this deployment.
"${COMPOSE[@]}" build --pull php worker migrate

# The compose graph requires `migrate` to finish successfully before php/worker
# start. Capture compose failures explicitly so migration diagnostics are not
# swallowed by `set -e`.
if ! "${COMPOSE[@]}" up -d --remove-orphans; then
  echo "docker compose up failed. Container state:" >&2
  "${COMPOSE[@]}" ps -a >&2 || true
  echo "Migration/MySQL logs:" >&2
  "${COMPOSE[@]}" logs --no-color --tail=250 migrate mysql >&2 || true
  migration_structured_log
  exit 24
fi

MIGRATE_ID="$("${COMPOSE[@]}" ps -aq migrate)"
if [[ -n "$MIGRATE_ID" ]]; then
  MIGRATE_STATUS="$("${DOCKER[@]}" inspect -f '{{.State.Status}}' "$MIGRATE_ID")"
  MIGRATE_EXIT_CODE="$("${DOCKER[@]}" inspect -f '{{.State.ExitCode}}' "$MIGRATE_ID")"
  if [[ "$MIGRATE_STATUS" == "exited" && "$MIGRATE_EXIT_CODE" != "0" ]]; then
    echo "Database migration failed with exit code $MIGRATE_EXIT_CODE." >&2
    "${COMPOSE[@]}" logs --no-color --tail=250 migrate mysql >&2 || true
    migration_structured_log
    exit 24
  fi
fi

PHP_ID="$("${COMPOSE[@]}" ps -q php)"
if [[ -z "$PHP_ID" ]]; then
  echo "PHP container was not created." >&2
  "${COMPOSE[@]}" ps -a >&2 || true
  exit 25
fi

for _ in $(seq 1 20); do
  PHP_HEALTH="$("${DOCKER[@]}" inspect -f '{{if .State.Health}}{{.State.Health.Status}}{{else}}{{.State.Status}}{{end}}' "$PHP_ID")"
  if [[ "$PHP_HEALTH" == "healthy" || "$PHP_HEALTH" == "running" ]]; then
    break
  fi
  if [[ "$PHP_HEALTH" == "unhealthy" || "$PHP_HEALTH" == "exited" || "$PHP_HEALTH" == "dead" ]]; then
    echo "PHP container entered state: $PHP_HEALTH" >&2
    "${COMPOSE[@]}" logs --no-color --tail=250 php >&2 || true
    exit 26
  fi
  sleep 3
done

PHP_HEALTH="$("${DOCKER[@]}" inspect -f '{{if .State.Health}}{{.State.Health.Status}}{{else}}{{.State.Status}}{{end}}' "$PHP_ID")"
if [[ "$PHP_HEALTH" != "healthy" && "$PHP_HEALTH" != "running" ]]; then
  echo "PHP container did not become ready; state: $PHP_HEALTH" >&2
  "${COMPOSE[@]}" logs --no-color --tail=250 php >&2 || true
  exit 27
fi


# Visualization is an operational observability surface. Exercise the same DI,
# graph provider, projection registry and Cytoscape mapper inside the deployed PHP
# image so a blank Architecture Explorer fails deployment with an exact stage.
if ! "${DOCKER[@]}" exec "$PHP_ID" php /var/www/html/bin/architecture-graph-smoke.php; then
  echo "Architecture Graph runtime smoke failed inside the deployed PHP container." >&2
  "${COMPOSE[@]}" logs --no-color --tail=250 php >&2 || true
  exit 30
fi

# docker compose does not recreate nginx when only a bind-mounted config file
# changes. Validate and reload it explicitly so the running process consumes the
# just-synced proxy contract instead of serving yesterday's configuration with
# today's files mounted underneath it.
NGINX_ID="$("${COMPOSE[@]}" ps -q nginx)"
if [[ -z "$NGINX_ID" ]]; then
  echo "Nginx container was not created." >&2
  "${COMPOSE[@]}" ps -a >&2 || true
  exit 28
fi

if ! "${DOCKER[@]}" exec "$NGINX_ID" nginx -t; then
  echo "Nginx container rejected the synced configuration." >&2
  "${COMPOSE[@]}" logs --no-color --tail=250 nginx >&2 || true
  exit 28
fi

if ! "${DOCKER[@]}" exec "$NGINX_ID" nginx -s reload; then
  echo "Nginx container could not reload the synced configuration." >&2
  "${COMPOSE[@]}" logs --no-color --tail=250 nginx >&2 || true
  exit 28
fi

echo "Nginx container configuration validated and reloaded."

# This is the blocking application check. Run it inside the server so a public
# CDN/WAF/TLS issue cannot make a healthy deployment look broken.
APP_HEALTHY=0
for _ in $(seq 1 15); do
  if "${DOCKER[@]}" exec "$NGINX_ID" wget -q -T 5 -O /dev/null http://127.0.0.1/cos; then
    APP_HEALTHY=1
    break
  fi
  sleep 2
done

if [[ "$APP_HEALTHY" != "1" ]]; then
  echo "Local application health check failed: http://127.0.0.1/cos" >&2
  "${COMPOSE[@]}" ps -a >&2 || true
  "${COMPOSE[@]}" logs --no-color --tail=250 nginx php >&2 || true
  exit 28
fi

echo "Local application health check passed: /cos"

# Canonical business/control-plane APIs no longer belong to the Phalcon host.
# Deploy the parallel Symfony runtime before the host reverse proxy is refreshed.
echo "Deploying canonical Symfony API runtime on 127.0.0.1:8081..."
bash deploy/symfony-dev.sh

if ! curl --fail --silent --show-error --retry 2 --retry-delay 1 \
    http://127.0.0.1:8081/api/v1/health > /tmp/cos-symfony-api-health.json; then
  echo "Canonical Symfony API health check failed on 127.0.0.1:8081." >&2
  cat /tmp/cos-symfony-api-health.json >&2 2>/dev/null || true
  exit 31
fi
echo "Canonical Symfony API runtime is healthy: /api/v1/health"

"${COMPOSE[@]}" ps
printf 'DEV deployment completed successfully.\n'
