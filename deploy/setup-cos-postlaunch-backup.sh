#!/usr/bin/env bash
set -Eeuo pipefail
cd /opt/cos
test -s .env.docker || { echo 'Missing COS runtime secrets'; exit 2; }
for container in cos-mysql-1 cos-redis-1 cos-php-1 cos-mercure-1; do
  state="$(sudo -n docker inspect -f '{{.State.Health.Status}}' "$container")"
  echo "$container health=$state"
  [[ "$state" == healthy ]] || exit 3
done
COUNT="$(sudo -n docker exec cos-mysql-1 sh -c 'MYSQL_PWD="$MYSQL_PASSWORD" exec mysql -N -u "$MYSQL_USER" "$MYSQL_DATABASE" -e "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE();"' | tr -d '[:space:]')"
[[ "$COUNT" =~ ^[0-9]+$ ]] || exit 4
(( COUNT >= 20 )) || { echo "COS schema incomplete ($COUNT tables)" >&2; exit 5; }
echo "COS_SCHEMA_TABLES=$COUNT"
ADMINS="$(sudo -n docker exec cos-mysql-1 sh -c 'MYSQL_PWD="$MYSQL_PASSWORD" exec mysql -N -u "$MYSQL_USER" "$MYSQL_DATABASE" -e "SELECT COUNT(*) FROM tn_users WHERE role=\"admin\" AND status=\"active\";"' | tr -d '[:space:]')"
echo "COS_ACTIVE_ADMIN_COUNT=$ADMINS"
curl -fsS --max-time 12 http://127.0.0.1:8081/health/dependencies >/dev/null
echo 'COS_LOCAL_HTTP_HEALTH=PASS'
if [[ -f .cos-fresh-db-bootstrap-in-progress ]]; then
  mv .cos-fresh-db-bootstrap-in-progress .cos-fresh-db-bootstrap-completed
else
  touch .cos-fresh-db-bootstrap-completed
fi
chmod 600 .cos-fresh-db-bootstrap-completed
sudo -n install -m 0750 -o root -g root deploy/cos-mysql-backup.sh /usr/local/sbin/cos-mysql-backup
sudo -n tee /etc/systemd/system/cos-mysql-backup.service >/dev/null <<'SERVICE'
[Unit]
Description=COS consistent local MySQL backup
After=docker.service
Requires=docker.service
[Service]
Type=oneshot
ExecStart=/usr/local/sbin/cos-mysql-backup
User=root
Group=root
NoNewPrivileges=true
SERVICE
sudo -n tee /etc/systemd/system/cos-mysql-backup.timer >/dev/null <<'TIMER'
[Unit]
Description=COS daily local MySQL backup timer
[Timer]
OnCalendar=*-*-* 03:20:00 UTC
RandomizedDelaySec=15m
Persistent=true
Unit=cos-mysql-backup.service
[Install]
WantedBy=timers.target
TIMER
sudo -n chmod 644 /etc/systemd/system/cos-mysql-backup.service /etc/systemd/system/cos-mysql-backup.timer
sudo -n systemctl daemon-reload
sudo -n systemctl enable --now cos-mysql-backup.timer
sudo -n systemctl start cos-mysql-backup.service
sudo -n systemctl is-active cos-mysql-backup.timer
echo 'COS_POST_LAUNCH_ACCEPTANCE_PASS (local backups only)'
