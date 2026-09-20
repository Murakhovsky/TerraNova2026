#!/usr/bin/env bash
set -Eeuo pipefail

DOMAIN="${1:-company-os.shop}"
UPSTREAM="${2:-127.0.0.1:8081}"
CERT_DIR="/etc/letsencrypt/live/$DOMAIN"
SITE_AVAILABLE="/etc/nginx/sites-available/$DOMAIN"
SITE_ENABLED="/etc/nginx/sites-enabled/$DOMAIN"
ACME_ROOT="/var/www/letsencrypt"

if ! command -v nginx >/dev/null 2>&1; then
  echo "Host nginx is not installed." >&2
  exit 40
fi
if ! command -v sudo >/dev/null 2>&1 || ! sudo -n true >/dev/null 2>&1; then
  echo "Passwordless sudo is required to configure host nginx." >&2
  exit 42
fi

sudo -n install -d -m 755 /etc/nginx/sites-available /etc/nginx/sites-enabled "$ACME_ROOT"

if ! sudo -n test -r "$CERT_DIR/fullchain.pem" || ! sudo -n test -r "$CERT_DIR/privkey.pem"; then
  if ! command -v certbot >/dev/null 2>&1; then
    echo "Certbot is required to issue the certificate for $DOMAIN." >&2
    exit 41
  fi
  sudo -n certbot certonly     --webroot --webroot-path "$ACME_ROOT"     --domain "$DOMAIN"     --non-interactive --agree-tos --register-unsafely-without-email --keep-until-expiring
fi

TMP_CONFIG="$(mktemp)"
TMP_RELOAD_HOOK="$(mktemp)"
trap 'rm -f "$TMP_CONFIG" "$TMP_RELOAD_HOOK"' EXIT

cat > "$TMP_CONFIG" <<EOF
server {
    listen 80;
    listen [::]:80;
    server_name $DOMAIN;

    location ^~ /.well-known/acme-challenge/ {
        root $ACME_ROOT;
        default_type text/plain;
        try_files \$uri =404;
    }

    location / {
        return 301 https://\$host\$request_uri;
    }
}

server {
    listen 80;
    listen [::]:80;
    server_name *.$DOMAIN;
    client_max_body_size 220m;

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

server {
    listen 443 ssl;
    listen [::]:443 ssl;
    server_name $DOMAIN;
    client_max_body_size 220m;

    ssl_certificate $CERT_DIR/fullchain.pem;
    ssl_certificate_key $CERT_DIR/privkey.pem;

    location / {
        proxy_http_version 1.1;
        proxy_set_header Host \$host;
        proxy_set_header X-Real-IP \$remote_addr;
        proxy_set_header X-Forwarded-For \$proxy_add_x_forwarded_for;
        proxy_set_header X-Forwarded-Proto https;
        proxy_set_header X-Forwarded-Host \$host;
        proxy_pass http://$UPSTREAM;
    }
}
EOF

sudo -n install -m 644 "$TMP_CONFIG" "$SITE_AVAILABLE"
sudo -n ln -sfn "$SITE_AVAILABLE" "$SITE_ENABLED"
sudo -n nginx -t
sudo -n systemctl reload nginx

cat > "$TMP_RELOAD_HOOK" <<'EOF'
#!/bin/sh
systemctl reload nginx
EOF
sudo -n install -m 755 "$TMP_RELOAD_HOOK" /etc/letsencrypt/renewal-hooks/deploy/reload-nginx
echo "HTTPS reverse proxy configured for $DOMAIN -> $UPSTREAM"
