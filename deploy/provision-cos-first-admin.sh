#!/usr/bin/env bash
# Controlled first-owner admin bootstrap. Never print passwords to Actions logs.
set -Eeuo pipefail
umask 077
cd /opt/cos
EMAIL='admin@company-os.shop'
CREDS='/root/.cos-first-admin-password'
[[ -s .env.docker ]] || { echo 'COS runtime secrets missing'; exit 2; }
[[ "$(sudo -n docker inspect -f '{{.State.Health.Status}}' cos-mysql-1)" == healthy ]] || {
  echo 'COS MySQL is not healthy'; exit 3;
}
[[ "$(sudo -n docker inspect -f '{{.State.Health.Status}}' cos-php-1)" == healthy ]] || {
  echo 'COS PHP is not healthy'; exit 4;
}
if sudo -n test -e "$CREDS"; then
  echo 'A first-admin password file already exists; refusing to replace it.'
  exit 5
fi
PASSFILE="$(mktemp /tmp/cos-admin-secret.XXXXXXXX)"
cleanup() {
  rm -f "$PASSFILE"
  sudo -n docker exec -u 0 cos-php-1 rm -f /tmp/cos-bootstrap-owner-admin.php >/dev/null 2>&1 || true
}
trap cleanup EXIT
openssl rand -hex 32 > "$PASSFILE"
chmod 600 "$PASSFILE"
# Save the recovery password before attempting an irreversible DB commit.
sudo -n install -m 600 -o root -g root "$PASSFILE" "$CREDS"
sudo -n docker cp deploy/cos-bootstrap-owner-admin.php cos-php-1:/tmp/cos-bootstrap-owner-admin.php
sudo -n docker exec -i cos-php-1 php /tmp/cos-bootstrap-owner-admin.php < "$PASSFILE"
echo 'INITIAL_ADMIN_LOGIN=https://company-os.shop/auth/login'
echo "INITIAL_ADMIN_EMAIL=$EMAIL"
echo "INITIAL_ADMIN_PASSWORD_STORED_ROOT_ONLY=$CREDS"
echo 'Get password via SSH: sudo cat /root/.cos-first-admin-password'
echo 'Delete credential file after storing and changing the password.'
