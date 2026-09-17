#!/usr/bin/env bash
set -Eeuo pipefail

PROJECT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$PROJECT_DIR"

if ! command -v docker >/dev/null 2>&1; then
  echo "Docker is not installed on the dev server." >&2
  exit 40
fi

if docker info >/dev/null 2>&1; then
  DOCKER=(docker)
elif command -v sudo >/dev/null 2>&1 && sudo -n docker info >/dev/null 2>&1; then
  DOCKER=(sudo -n docker)
else
  echo "Deploy user cannot access Docker." >&2
  exit 41
fi

if ! "${DOCKER[@]}" compose version >/dev/null 2>&1; then
  echo "Docker Compose v2 is required." >&2
  exit 42
fi

CONFIG_DIR="${COS_SYMFONY_CONFIG_DIR:-$HOME/.config/cos-symfony}"
ENV_FILE="$CONFIG_DIR/runtime.env"
mkdir -p "$CONFIG_DIR"
chmod 700 "$CONFIG_DIR"
umask 077
touch "$ENV_FILE"
chmod 600 "$ENV_FILE"

if ! command -v openssl >/dev/null 2>&1; then
  echo "openssl is required to manage Symfony runtime secrets." >&2
  exit 43
fi

ensure_secret() {
  local key="$1"
  local bytes="$2"
  if ! grep -q "^${key}=" "$ENV_FILE"; then
    printf '%s=%s\n' "$key" "$(openssl rand -hex "$bytes")" >> "$ENV_FILE"
    echo "Added persistent runtime secret: $key"
  fi
}

upsert_value() {
  local key="$1"
  local value="$2"
  if grep -q "^${key}=" "$ENV_FILE"; then
    sed -i "s|^${key}=.*|${key}=${value}|" "$ENV_FILE"
  else
    printf '%s=%s\n' "$key" "$value" >> "$ENV_FILE"
  fi
}

read_value() {
  local key="$1"
  awk -F= -v key="$key" '$1 == key {sub(/^[^=]*=/, ""); print; exit}' "$ENV_FILE"
}

ensure_secret SYMFONY_APP_SECRET 32
ensure_secret SYMFONY_DB_ROOT_PASSWORD 24
ensure_secret SYMFONY_DB_PASSWORD 24
ensure_secret SYMFONY_LEGACY_DB_PASSWORD 24

LEGACY_NETWORK="${COS_LEGACY_DOCKER_NETWORK:-cos_backend}"
LEGACY_MYSQL_CONTAINER="${COS_LEGACY_MYSQL_CONTAINER:-cos-mysql-1}"
LEGACY_PHP_CONTAINER="${COS_LEGACY_PHP_CONTAINER:-cos-php-1}"
LEGACY_SESSION_VOLUME="${COS_LEGACY_SESSION_VOLUME:-cos_php_sessions}"

if ! "${DOCKER[@]}" network inspect "$LEGACY_NETWORK" >/dev/null 2>&1; then
  echo "Legacy COS Docker network is unavailable: $LEGACY_NETWORK" >&2
  exit 45
fi

if ! "${DOCKER[@]}" inspect "$LEGACY_MYSQL_CONTAINER" >/dev/null 2>&1; then
  echo "Legacy COS MySQL container is unavailable: $LEGACY_MYSQL_CONTAINER" >&2
  exit 46
fi

if ! "${DOCKER[@]}" volume inspect "$LEGACY_SESSION_VOLUME" >/dev/null 2>&1; then
  echo "Legacy COS session volume is unavailable: $LEGACY_SESSION_VOLUME" >&2
  exit 53
fi

LEGACY_DB_NAME="$("${DOCKER[@]}" exec "$LEGACY_MYSQL_CONTAINER" printenv MYSQL_DATABASE | tr -d '\r\n')"
if [[ ! "$LEGACY_DB_NAME" =~ ^[A-Za-z0-9_]+$ ]]; then
  echo "Legacy COS database name is invalid or unavailable." >&2
  exit 47
fi
upsert_value SYMFONY_LEGACY_DB_NAME "$LEGACY_DB_NAME"
upsert_value SYMFONY_LEGACY_DB_HOST "$LEGACY_MYSQL_CONTAINER"

LEGACY_ORGANIZATION_ID="default"
if "${DOCKER[@]}" inspect "$LEGACY_PHP_CONTAINER" >/dev/null 2>&1; then
  DETECTED_ORGANIZATION_ID="$("${DOCKER[@]}" exec "$LEGACY_PHP_CONTAINER" sh -c 'printf "%s" "${COS_ORGANIZATION_ID:-default}"' 2>/dev/null || true)"
  if [[ -n "$DETECTED_ORGANIZATION_ID" ]]; then
    LEGACY_ORGANIZATION_ID="$DETECTED_ORGANIZATION_ID"
  fi
fi
if [[ ! "$LEGACY_ORGANIZATION_ID" =~ ^[A-Za-z0-9._:-]+$ ]]; then
  echo "Legacy COS organization id is invalid or unavailable." >&2
  exit 52
fi
upsert_value COS_ORGANIZATION_ID "$LEGACY_ORGANIZATION_ID"
echo "Symfony migration tenant is fixed to legacy COS organization: $LEGACY_ORGANIZATION_ID"

LEGACY_DB_PASSWORD="$(read_value SYMFONY_LEGACY_DB_PASSWORD)"
if [[ ! "$LEGACY_DB_PASSWORD" =~ ^[a-f0-9]{48}$ ]]; then
  echo "Symfony legacy read-only password has an unexpected format." >&2
  exit 48
fi

printf -v LEGACY_GRANTS \
  "CREATE USER IF NOT EXISTS 'cos_symfony_ro'@'%%' IDENTIFIED BY '%s'; ALTER USER 'cos_symfony_ro'@'%%' IDENTIFIED BY '%s'; GRANT SELECT ON \`%s\`.* TO 'cos_symfony_ro'@'%%'; FLUSH PRIVILEGES;" \
  "$LEGACY_DB_PASSWORD" "$LEGACY_DB_PASSWORD" "$LEGACY_DB_NAME"

if ! printf '%s\n' "$LEGACY_GRANTS" | "${DOCKER[@]}" exec -i "$LEGACY_MYSQL_CONTAINER" sh -c 'exec mysql -uroot -p"$MYSQL_ROOT_PASSWORD"'; then
  echo "Could not provision the Symfony read-only account in legacy COS MySQL." >&2
  exit 49
fi

echo "Legacy COS read-only database account is ready."

COMPOSE=("${DOCKER[@]}" compose --env-file "$ENV_FILE" -f docker-compose.symfony.yml)

"${COMPOSE[@]}" config --quiet
"${COMPOSE[@]}" build --pull php nginx
"${COMPOSE[@]}" up -d --remove-orphans

for attempt in $(seq 1 30); do
  if "${DOCKER[@]}" exec cos-symfony-nginx-1 wget -q -T 5 -O /dev/null http://127.0.0.1/health 2>/dev/null; then
    echo "Symfony health check passed on attempt $attempt."
    break
  fi
  sleep 2
done

if ! "${DOCKER[@]}" exec cos-symfony-nginx-1 wget -q -T 5 -O /dev/null http://127.0.0.1/health 2>/dev/null; then
  echo "Symfony runtime failed its health check." >&2
  "${COMPOSE[@]}" ps -a >&2 || true
  "${COMPOSE[@]}" logs --no-color --tail=250 nginx php mysql redis >&2 || true
  exit 44
fi

CORE_HEALTHY=0
for attempt in $(seq 1 20); do
  if "${DOCKER[@]}" exec cos-symfony-nginx-1 wget -q -T 5 -O /tmp/core-health.json http://127.0.0.1/migration/core-health 2>/dev/null; then
    CORE_HEALTHY=1
    echo "Shared COS read-model check passed on attempt $attempt."
    "${DOCKER[@]}" exec cos-symfony-nginx-1 cat /tmp/core-health.json
    echo
    break
  fi
  sleep 2
done

if [[ "$CORE_HEALTHY" != "1" ]]; then
  echo "Symfony could not execute the shared COS OperationsReadModel against the legacy database." >&2
  "${COMPOSE[@]}" ps -a >&2 || true
  "${COMPOSE[@]}" logs --no-color --tail=250 nginx php >&2 || true
  exit 50
fi

PROTECTED_STATUS="$(curl --silent --show-error --output /tmp/cos-symfony-protected.json --write-out '%{http_code}' http://127.0.0.1:8081/migration/api/cos/events)"
if [[ "$PROTECTED_STATUS" != "403" ]] || [[ "$(cat /tmp/cos-symfony-protected.json)" != '{"ok":false,"error":"Manager authorization required."}' ]]; then
  echo "Symfony Security did not protect the Operations migration API as expected." >&2
  cat /tmp/cos-symfony-protected.json >&2 || true
  echo >&2
  "${COMPOSE[@]}" ps -a >&2 || true
  "${COMPOSE[@]}" logs --no-color --tail=250 nginx php >&2 || true
  exit 51
fi

echo "Operations migration API is protected by the legacy-session Symfony Security bridge."

"${COMPOSE[@]}" ps
echo "Parallel Symfony runtime is available at http://127.0.0.1:8081/health"
echo "Shared core read-model probe is available at http://127.0.0.1:8081/migration/core-health"
echo "Protected Operations migration API is available at http://127.0.0.1:8081/migration/api/cos/events"
