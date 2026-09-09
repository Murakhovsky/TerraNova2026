#!/usr/bin/env bash
set -Eeuo pipefail

DOMAIN="${1:-aida.terra-nova.site}"
UPSTREAM="${2:-127.0.0.1:8080}"
CERT_DIR="/etc/letsencrypt/live/$DOMAIN"
SITE_AVAILABLE="/etc/nginx/sites-available/$DOMAIN"
SITE_ENABLED="/etc/nginx/sites-enabled/$DOMAIN"

if ! command -v nginx >/dev/null 2>&1; then
  echo "Host nginx is not installed." >&2
  exit 40
fi

if [[ ! -r "$CERT_DIR/fullchain.pem" || ! -r "$CERT_DIR/privkey.pem" ]]; then
  echo "Let's Encrypt certificate for $DOMAIN is missing." >&2
  exit 41
fi

if ! command -v sudo >/dev/null 2>&1 || ! sudo -n true >/dev/null 2>&1; then
  echo "Passwordless sudo is required to configure host nginx." >&2
  exit 42
fi

sudo -n install -d -m 755 /etc/nginx/sites-available /etc/nginx/sites-enabled /var/www/letsencrypt

TMP_CONFIG="$(mktemp)"
trap 'rm -f "$TMP_CONFIG"' EXIT

cat > "$TMP_CONFIG" <<EOF
server {
    listen 80;
    listen [::]:80;
    server_name $DOMAIN;

    location /.well-known/acme-challenge/ {
        root /var/www/letsencrypt;
    }

    location / {
        return 301 https://\$host\$request_uri;
    }
}

server {
    listen 443 ssl;
    listen [::]:443 ssl;
    server_name $DOMAIN;

    ssl_certificate $CERT_DIR/fullchain.pem;
    ssl_certificate_key $CERT_DIR/privkey.pem;
    ssl_protocols TLSv1.2 TLSv1.3;
    ssl_session_cache shared:SSL:10m;
    ssl_session_timeout 10m;

    client_max_body_size 100m;

    location / {
        proxy_pass http://$UPSTREAM;
        proxy_http_version 1.1;
        proxy_set_header Host \$host;
        proxy_set_header X-Real-IP \$remote_addr;
        proxy_set_header X-Forwarded-For \$proxy_add_x_forwarded_for;
        proxy_set_header X-Forwarded-Proto https;
        proxy_set_header X-Forwarded-Host \$host;
        proxy_set_header X-Forwarded-Port 443;
        proxy_read_timeout 120s;
        proxy_send_timeout 120s;
    }
}
EOF

sudo -n install -m 644 "$TMP_CONFIG" "$SITE_AVAILABLE"
sudo -n ln -sfn "$SITE_AVAILABLE" "$SITE_ENABLED"
sudo -n nginx -t
sudo -n systemctl reset-failed nginx >/dev/null 2>&1 || true
sudo -n systemctl enable nginx >/dev/null 2>&1 || true
sudo -n systemctl restart nginx

for _ in $(seq 1 15); do
  if curl --fail --silent --show-error \
      --resolve "$DOMAIN:443:127.0.0.1" \
      "https://$DOMAIN/cos" > /dev/null; then
    echo "HTTPS reverse proxy is healthy for $DOMAIN."
    if systemctl list-unit-files certbot.timer >/dev/null 2>&1; then
      sudo -n systemctl enable --now certbot.timer >/dev/null 2>&1 || true
    fi
    exit 0
  fi
  sleep 2
done

echo "HTTPS health check failed for $DOMAIN." >&2
sudo -n ss -ltnp 2>/dev/null | grep -E ':(80|443|8080)[[:space:]]' >&2 || true
sudo -n journalctl -u nginx -n 80 --no-pager >&2 || true
exit 43
