#!/usr/bin/env bash
# Bootstrap a NEW empty COS database on a verified replacement host.
# Existing historical business records are not recovered by this command.
set -Eeuo pipefail
umask 077
cd "$1"
PROJECT_ROOT="$(pwd -P)"
if [[ "$PROJECT_ROOT" != /opt/cos ]]; then
  echo "Unexpected COS directory; refusing database bootstrap." >&2; exit 2
fi
[[ -f docker-compose.yml && -f deploy/dev.sh ]] || { echo "COS source absent"; exit 3; }
sudo -n docker info >/dev/null || { echo "Docker daemon inaccessible"; exit 4; }

MARKER=".cos-fresh-db-bootstrap-in-progress"
if [[ -e .env || -f .cos-fresh-db-bootstrap-completed ]]; then
  echo "Pre-existing or completed COS deployment detected. Refusing reinitialization." >&2; exit 5
fi
# Prior runtime rsync --delete dropped our private in-progress marker but kept
# the newly generated .env.docker. A one-time safe resume is allowed ONLY if
# it matches this bootstrap's fresh disabled-worker configuration AND Docker
# is still entirely empty. Never change the existing secrets.
if [[ -s .env.docker && ! -f "$MARKER" ]]; then
  if [[ -n "$(sudo -n docker ps -aq)" || -n "$(sudo -n docker volume ls -q)" ]]; then
    echo "Cannot resume unmarked env when database resources exist." >&2; exit 12
  fi
  for key in MYSQL_PASSWORD MYSQL_ROOT_PASSWORD SYMFONY_APP_SECRET COS_SECRET_MASTER_KEY SPATIAL_JWT_SECRET MERCURE_JWT_SECRET; do
    grep -Eq "^$key=.{20,}$" .env.docker || { echo "Existing env does not match new COS credentials layout." >&2; exit 13; }
  done
  grep -Fxq 'COS_SALES_AUTOMATION_SCHEDULER_ENABLED=0' .env.docker || exit 14
  grep -Fxq 'FOS_L2_ENABLED=0' .env.docker || exit 14
  : > "$MARKER"
  chmod 600 "$MARKER"
  echo 'RECOVERED_FIRST_BOOT_MARKER (existing secrets preserved)'
fi
if [[ ! -f "$MARKER" ]]; then
  # Only allow first run when the host has no prior Docker data.
  if [[ -n "$(sudo -n docker ps -aq)" || -n "$(sudo -n docker volume ls -q)" ]]; then
    echo "Containers or volumes exist. Refusing fresh database initialization." >&2; exit 6
  fi
  FREE_GIB="$(df -BG --output=avail / | tail -1 | tr -dc '0-9')"
  if (( FREE_GIB < 7 )); then echo "Insufficient free space" >&2; exit 7; fi
  if sudo -n find /var/backups /mnt /srv -maxdepth 4 -type f \
       \( -iname '*.sql.gz' -o -iname '*.sql.zst' -o -iname '*mysql*.tar*' -o -iname '*cos*backup*' \) 2>/dev/null | grep -q .; then
    echo "Potential backup found. Refusing fresh DB initialization." >&2; exit 8
  fi
  if [[ -e .env.docker ]]; then echo "Pre-existing env file detected" >&2; exit 9; fi

  TMP="$(mktemp ./.env.docker.new.XXXXXXXX)"
  trap 'rm -f "$TMP"' EXIT
  {
    printf 'MYSQL_DATABASE=cos\n'
    printf 'MYSQL_USER=cos\n'
    printf 'MYSQL_PASSWORD=%s\n' "$(openssl rand -hex 32)"
    printf 'MYSQL_ROOT_PASSWORD=%s\n' "$(openssl rand -hex 32)"
    printf 'SYMFONY_APP_SECRET=%s\n' "$(openssl rand -hex 32)"
    printf 'COS_SECRET_MASTER_KEY=%s\n' "$(openssl rand -base64 32 | tr -d '\n')"
    printf 'COS_SECRET_MASTER_KEY_ID=env-v1\n'
    printf 'SPATIAL_JWT_SECRET=%s\n' "$(openssl rand -hex 32)"
    printf 'MERCURE_JWT_SECRET=%s\n' "$(openssl rand -hex 32)"
    printf 'COS_ORGANIZATION_ID=default\n'
    printf 'APP_URL=https://company-os.shop\n'
    printf 'MERCURE_PUBLIC_URL=https://company-os.shop/.well-known/mercure\n'
    printf 'COS_SALES_AUTOMATION_SCHEDULER_ENABLED=0\n'
    printf 'COS_ENGINEERING_RUNTIME_WATCHDOG_ENABLED=0\n'
    printf 'COS_ENGINEERING_AUTONOMY_SCHEDULER_ENABLED=0\n'
    printf 'COS_ENGINEERING_DOMAIN_AUTONOMY_SCHEDULER_ENABLED=0\n'
    printf 'FOS_L2_ENABLED=0\n'
    printf 'COS_SIWC_ENABLED=0\n'
    printf 'COS_ENGINEERING_GITHUB_REPOSITORY=Murakhovsky/TerraNova2026\n'
    printf 'COS_ENGINEERING_GITHUB_TOKEN=\n'
    printf 'LLM_ENDPOINT=\n'
    printf 'LLM_TOKEN=\n'
    printf 'LLM_MODEL=\n'
  } > "$TMP"
  chmod 600 "$TMP"
  mv "$TMP" .env.docker
  : > "$MARKER"
  chmod 600 "$MARKER"
  echo 'NEW_COS_ENV_CREATED (sensitive values redacted)'
else
  [[ -s .env.docker ]] || { echo "Incomplete bootstrap marker, .env.docker missing"; exit 10; }
  echo 'RESUME_EXISTING_NEW_DATABASE_BOOTSTRAP'
fi

export COS_NEW_DB_BOOTSTRAP=1
export COS_DEPLOY_MODE=registry COS_DEPLOY_TIER=core
export COS_PHP_IMAGE=ghcr.io/murakhovsky/terranova2026-php:ee326a0d205e5a9d39e2017e11690d02aa541a52
export COS_NGINX_IMAGE=ghcr.io/murakhovsky/terranova2026-nginx:ee326a0d205e5a9d39e2017e11690d02aa541a52

for img in "$COS_PHP_IMAGE" "$COS_NGINX_IMAGE" mysql:8.4 redis:7.4-alpine dunglas/mercure:v0.24.2; do
  sudo -n docker image inspect "$img" >/dev/null || { echo "Image not prestaged: $img" >&2; exit 11; }
done

# Resume previously initialized secrets after fixing sudo GHCR image forwarding.
echo "MIGRATING_NEW_COS_DATABASE"
bash deploy/dev.sh
echo "COS_DOCKER_HEALTH_OK"
bash deploy/configure-company-os-http.sh company-os.shop 127.0.0.1:8081

DNS_IPV4="$(getent ahostsv4 company-os.shop | awk 'NR==1 {print $1}' || true)"
HOST_IPV4="$(curl -fsS4 --max-time 8 https://checkip.amazonaws.com 2>/dev/null | tr -d '[:space:]' || true)"
if [[ -n "$DNS_IPV4" && "$DNS_IPV4" == "$HOST_IPV4" ]]; then
  echo 'DNS_MATCHES_HOST: requesting HTTPS certificate.'
  bash deploy/configure-dev-tls.sh company-os.shop 127.0.0.1:8081
else
  echo 'HTTPS_PENDING_DNS: domain does not currently resolve to this VPS.'
fi

sudo -n docker ps --format '{{.Names}} | {{.Status}}'
echo 'New COS database table count:'
sudo -n docker exec cos-mysql-1 sh -c 'mysql -N -u"$MYSQL_USER" -p"$MYSQL_PASSWORD" "$MYSQL_DATABASE" -e "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE();"' || true
mv "$MARKER" .cos-fresh-db-bootstrap-completed
echo 'COS_NEW_DATABASE_BOOTSTRAP_SUCCESS (historical records not restored)'
