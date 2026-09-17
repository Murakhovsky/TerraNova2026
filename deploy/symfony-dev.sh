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

if [[ ! -f "$ENV_FILE" ]]; then
  if ! command -v openssl >/dev/null 2>&1; then
    echo "openssl is required to generate Symfony runtime secrets." >&2
    exit 43
  fi

  umask 077
  cat > "$ENV_FILE" <<EOF
SYMFONY_APP_SECRET=$(openssl rand -hex 32)
SYMFONY_DB_ROOT_PASSWORD=$(openssl rand -hex 24)
SYMFONY_DB_PASSWORD=$(openssl rand -hex 24)
EOF
  chmod 600 "$ENV_FILE"
  echo "Created persistent Symfony runtime secrets in $ENV_FILE"
fi

COMPOSE=("${DOCKER[@]}" compose --env-file "$ENV_FILE" -f docker-compose.symfony.yml)

"${COMPOSE[@]}" config --quiet
"${COMPOSE[@]}" build --pull php nginx
"${COMPOSE[@]}" up -d --remove-orphans

for attempt in $(seq 1 30); do
  if "${DOCKER[@]}" exec cos-symfony-nginx-1 wget -q -T 5 -O /dev/null http://127.0.0.1/health 2>/dev/null; then
    echo "Symfony health check passed on attempt $attempt."
    "${COMPOSE[@]}" ps
    echo "Parallel Symfony runtime is available at http://127.0.0.1:8081/health"
    exit 0
  fi
  sleep 2
done

echo "Symfony runtime failed its health check." >&2
"${COMPOSE[@]}" ps -a >&2 || true
"${COMPOSE[@]}" logs --no-color --tail=250 nginx php mysql redis >&2 || true
exit 44
