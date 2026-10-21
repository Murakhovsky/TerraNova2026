#!/usr/bin/env bash
set -Eeuo pipefail
umask 077
[[ "$(id -u)" -eq 0 ]] || exit 2
test -s /opt/cos/.env.docker || exit 3
[[ "$(docker inspect cos-mysql-1 --format '{{.State.Health.Status}}')" == healthy ]] || exit 4
mkdir -p /var/backups/cos
chmod 700 /var/backups/cos
exec 9>/var/backups/cos/.backup.lock
flock -n 9 || exit 0
STAMP="$(date -u +%Y%m%dT%H%M%SZ)"
TMP="$(mktemp /var/backups/cos/.sql-tmp.XXXXXX)"
trap 'rm -f "$TMP"' EXIT
docker exec cos-mysql-1 sh -c 'MYSQL_PWD="$MYSQL_PASSWORD" exec mysqldump -u "$MYSQL_USER" --single-transaction --quick --no-tablespaces --skip-lock-tables --set-gtid-purged=OFF "$MYSQL_DATABASE"' | gzip -1 > "$TMP"
gzip -t "$TMP"
(( $(stat -c %s "$TMP") > 300 )) || exit 5
TARGET="/var/backups/cos/mysql-$STAMP.sql.gz"
mv "$TMP" "$TARGET"
chmod 600 "$TARGET"
install -m 600 /opt/cos/.env.docker /var/backups/cos/cos-runtime-keys.env.backup
find /var/backups/cos -maxdepth 1 -type f -name 'mysql-*.sql.gz' -mtime +14 -delete
echo "COS_MYSQL_LOCAL_BACKUP_PASS bytes=$(stat -c %s "$TARGET")"
echo "OFFSITE_BACKUP_NOT_CONFIGURED"
