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
  DOCKER=(sudo -n docker)
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

ensure_secret SYMFONY_APP_SECRET
ensure_secret SPATIAL_JWT_SECRET
ensure_secret MERCURE_JWT_SECRET

"${COMPOSE[@]}" config --quiet
"${COMPOSE[@]}" build --pull php nginx

# Port 8081 is reserved for the canonical COS HTTP runtime. Older compose
# projects can survive a runtime cutover and keep that host binding even
# after their files have been retired. Remove only foreign Docker owners;
# the canonical cos/nginx container is left for Compose to reconcile.
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

"${COMPOSE[@]}" up -d mysql redis mercure

for attempt in $(seq 1 30); do
  if "${COMPOSE[@]}" exec -T mysql sh -c 'mysqladmin ping -h 127.0.0.1 -u"$MYSQL_USER" -p"$MYSQL_PASSWORD" --silent' >/dev/null 2>&1; then
    break
  fi
  sleep 2
done

for attempt in $(seq 1 30); do
  mercure_id="$("${COMPOSE[@]}" ps -q mercure)"
  if [[ -n "$mercure_id" ]] && [[ "$("${DOCKER[@]}" inspect -f '{{.State.Health.Status}}' "$mercure_id" 2>/dev/null || true)" == "healthy" ]]; then
    break
  fi
  sleep 2
done

mercure_id="$("${COMPOSE[@]}" ps -q mercure)"
if [[ -z "$mercure_id" ]] || [[ "$("${DOCKER[@]}" inspect -f '{{.State.Health.Status}}' "$mercure_id" 2>/dev/null || true)" != "healthy" ]]; then
  echo "Mercure failed readiness before canonical HTTP startup." >&2
  "${COMPOSE[@]}" logs --no-color --tail=200 mercure >&2 || true
  exit 32
fi

"${COMPOSE[@]}" run --rm --no-deps php php bin/console cos:schema:migrate
"${COMPOSE[@]}" run --rm --no-deps php php bin/console doctrine:migrations:migrate --no-interaction --allow-no-migration
"${COMPOSE[@]}" up -d --remove-orphans

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
printf 'COS Symfony deployment completed successfully.\n'
