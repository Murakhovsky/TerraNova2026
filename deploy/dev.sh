#!/usr/bin/env bash
set -Eeuo pipefail

PROJECT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$PROJECT_DIR"

if ! command -v docker >/dev/null 2>&1; then
  echo "Docker is not installed on the dev server." >&2
  exit 20
fi

if ! docker compose version >/dev/null 2>&1; then
  echo "Docker Compose v2 is not available on the dev server." >&2
  exit 21
fi

if [[ -f .env ]]; then
  ENV_FILE=".env"
  COMPOSE=(docker compose)
elif [[ -f .env.docker ]]; then
  ENV_FILE=".env.docker"
  COMPOSE=(docker compose --env-file .env.docker)
else
  echo "Neither .env nor .env.docker exists in $PROJECT_DIR." >&2
  echo "Create the server environment file before deploying." >&2
  exit 22
fi

echo "Using server environment: $ENV_FILE"
"${COMPOSE[@]}" config --quiet

# Build application images from the just-synced COS revision. The MySQL volume
# is persistent and is never replaced by this deployment.
"${COMPOSE[@]}" build --pull php worker migrate

# The compose graph already requires `migrate` to complete successfully before
# php/worker start, so every deployment applies pending schema migrations first.
"${COMPOSE[@]}" up -d --remove-orphans

MIGRATE_ID="$("${COMPOSE[@]}" ps -aq migrate)"
if [[ -n "$MIGRATE_ID" ]]; then
  MIGRATE_STATUS="$(docker inspect -f '{{.State.Status}}' "$MIGRATE_ID")"
  MIGRATE_EXIT_CODE="$(docker inspect -f '{{.State.ExitCode}}' "$MIGRATE_ID")"
  if [[ "$MIGRATE_STATUS" == "exited" && "$MIGRATE_EXIT_CODE" != "0" ]]; then
    echo "Database migration failed with exit code $MIGRATE_EXIT_CODE." >&2
    "${COMPOSE[@]}" logs --no-color migrate >&2 || true
    exit 23
  fi
fi

PHP_ID="$("${COMPOSE[@]}" ps -q php)"
if [[ -z "$PHP_ID" ]]; then
  echo "PHP container was not created." >&2
  "${COMPOSE[@]}" ps >&2 || true
  exit 24
fi

for _ in $(seq 1 20); do
  PHP_HEALTH="$(docker inspect -f '{{if .State.Health}}{{.State.Health.Status}}{{else}}{{.State.Status}}{{end}}' "$PHP_ID")"
  if [[ "$PHP_HEALTH" == "healthy" || "$PHP_HEALTH" == "running" ]]; then
    break
  fi
  if [[ "$PHP_HEALTH" == "unhealthy" || "$PHP_HEALTH" == "exited" || "$PHP_HEALTH" == "dead" ]]; then
    echo "PHP container entered state: $PHP_HEALTH" >&2
    "${COMPOSE[@]}" logs --no-color php >&2 || true
    exit 25
  fi
  sleep 3
done

PHP_HEALTH="$(docker inspect -f '{{if .State.Health}}{{.State.Health.Status}}{{else}}{{.State.Status}}{{end}}' "$PHP_ID")"
if [[ "$PHP_HEALTH" != "healthy" && "$PHP_HEALTH" != "running" ]]; then
  echo "PHP container did not become ready; state: $PHP_HEALTH" >&2
  "${COMPOSE[@]}" logs --no-color php >&2 || true
  exit 26
fi

"${COMPOSE[@]}" ps
printf 'DEV deployment completed successfully.\n'
