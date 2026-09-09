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
  exit 24
fi

MIGRATE_ID="$("${COMPOSE[@]}" ps -aq migrate)"
if [[ -n "$MIGRATE_ID" ]]; then
  MIGRATE_STATUS="$("${DOCKER[@]}" inspect -f '{{.State.Status}}' "$MIGRATE_ID")"
  MIGRATE_EXIT_CODE="$("${DOCKER[@]}" inspect -f '{{.State.ExitCode}}' "$MIGRATE_ID")"
  if [[ "$MIGRATE_STATUS" == "exited" && "$MIGRATE_EXIT_CODE" != "0" ]]; then
    echo "Database migration failed with exit code $MIGRATE_EXIT_CODE." >&2
    "${COMPOSE[@]}" logs --no-color --tail=250 migrate mysql >&2 || true
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

# This is the blocking application check. Run it inside the server so a public
# CDN/WAF/TLS issue cannot make a healthy deployment look broken.
NGINX_ID="$("${COMPOSE[@]}" ps -q nginx)"
if [[ -z "$NGINX_ID" ]]; then
  echo "Nginx container was not created." >&2
  "${COMPOSE[@]}" ps -a >&2 || true
  exit 28
fi

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
"${COMPOSE[@]}" ps
printf 'DEV deployment completed successfully.\n'
