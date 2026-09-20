#!/usr/bin/env bash
set -Eeuo pipefail

DOMAIN="${1:-company-os.shop}"
UPSTREAM="${2:-127.0.0.1:8081}"
SITE_AVAILABLE="/etc/nginx/sites-available/$DOMAIN"
SITE_ENABLED="/etc/nginx/sites-enabled/$DOMAIN"
CERT_DIR="/etc/letsencrypt/live/$DOMAIN"
ACME_ROOT="/var/www/letsencrypt"

if ! command -v nginx >/dev/null 2>&1; then
  echo "Host nginx is not installed." >&2
  exit 50
fi
if ! command -v sudo >/dev/null 2>&1 || ! sudo -n true >/dev/null 2>&1; then
  echo "Passwordless sudo is required to configure host nginx." >&2
  exit 51
fi

sudo -n install -d -m 755 /etc/nginx/sites-available /etc/nginx/sites-enabled "$ACME_ROOT"

TLS_LISTEN_PATTERN='^[[:space:]]*listen[[:space:]]+([^;[:space:]]*:)?443([[:space:]]+[^;[:space:]]+)*[[:space:]]+ssl([[:space:]]+[^;[:space:]]+)*[[:space:]]*;'
if sudo -n test -r "$SITE_AVAILABLE"     && sudo -n test -r "$CERT_DIR/fullchain.pem"     && sudo -n test -r "$CERT_DIR/privkey.pem"     && sudo -n grep -Eq "$TLS_LISTEN_PATTERN" "$SITE_AVAILABLE"; then
  sudo -n ln -sfn "$SITE_AVAILABLE" "$SITE_ENABLED"
  sudo -n nginx -t
  sudo -n systemctl reload nginx
  echo "Existing HTTPS vhost preserved for $DOMAIN."
  exit 0
fi

TMP_CONFIG="$(mktemp)"
trap 'rm -f "$TMP_CONFIG"' EXIT

cat > "$TMP_CONFIG" <<EOF
server {
    listen 80;
    listen [::]:80;
    server_name $DOMAIN *.$DOMAIN;
    client_max_body_size 220m;

    location ^~ /.well-known/acme-challenge/ {
        root $ACME_ROOT;
        default_type text/plain;
        try_files \$uri =404;
    }

    location / {
        proxy_http_version 1.1;
        proxy_set_header Host \$host;
        proxy_set_header X-Real-IP \$remote_addr;
        proxy_set_header X-Forwarded-For \$proxy_add_x_forwarded_for;
        proxy_set_header X-Forwarded-Proto \$scheme;
        proxy_set_header X-Forwarded-Host \$host;
        proxy_pass http://$UPSTREAM;
    }
}
EOF

sudo -n install -m 644 "$TMP_CONFIG" "$SITE_AVAILABLE"
sudo -n ln -sfn "$SITE_AVAILABLE" "$SITE_ENABLED"
sudo -n nginx -t
sudo -n systemctl reload nginx
echo "HTTP reverse proxy configured for $DOMAIN -> $UPSTREAM"
