#!/usr/bin/env bash
set -Eeuo pipefail

DOMAIN="${1:-company-os.shop}"
UPSTREAM="${2:-127.0.0.1:8080}"
SITE_AVAILABLE="/etc/nginx/sites-available/$DOMAIN"
SITE_ENABLED="/etc/nginx/sites-enabled/$DOMAIN"
CERT_DIR="/etc/letsencrypt/live/$DOMAIN"

if ! command -v nginx >/dev/null 2>&1; then
  echo "Host nginx is not installed." >&2
  exit 50
fi

if ! command -v sudo >/dev/null 2>&1 || ! sudo -n true >/dev/null 2>&1; then
  echo "Passwordless sudo is required to configure host nginx." >&2
  exit 51
fi

sudo -n install -d -m 755 /etc/nginx/sites-available /etc/nginx/sites-enabled /var/www/letsencrypt

# A normal deploy must never replace a working TLS vhost with an HTTP-only one.
# Accept valid listen variants such as `listen 443 ssl http2;`,
# `listen 443 default_server ssl;` and `listen [::]:443 ssl;` rather than
# depending on one literal spelling of the nginx directive.
TLS_LISTEN_PATTERN='^[[:space:]]*listen[[:space:]]+([^;[:space:]]*:)?443([[:space:]]+[^;[:space:]]+)*[[:space:]]+ssl([[:space:]]+[^;[:space:]]+)*[[:space:]]*;'
if sudo -n test -r "$SITE_AVAILABLE" \
    && sudo -n test -r "$CERT_DIR/fullchain.pem" \
    && sudo -n test -r "$CERT_DIR/privkey.pem" \
    && sudo -n grep -Eq "$TLS_LISTEN_PATTERN" "$SITE_AVAILABLE"; then
  sudo -n ln -sfn "$SITE_AVAILABLE" "$SITE_ENABLED"
  sudo -n nginx -t
  sudo -n systemctl reload nginx
  echo "Existing HTTPS vhost preserved for $DOMAIN; HTTP bootstrap skipped."
  exit 0
fi

TMP_CONFIG="$(mktemp)"
trap 'rm -f "$TMP_CONFIG"' EXIT

cat > "$TMP_CONFIG" <<EOF
server {
    listen 80;
    listen [::]:80;
    server_name $DOMAIN *.$DOMAIN;

    client_max_body_size 100m;

    # Keep the ACME HTTP-01 challenge on the host nginx so Certbot can issue
    # and renew the apex certificate without touching the application container.
    location ^~ /.well-known/acme-challenge/ {
        root /var/www/letsencrypt;
        default_type text/plain;
        try_files \$uri =404;
    }

    location / {
        proxy_pass http://$UPSTREAM;
        proxy_http_version 1.1;
        proxy_set_header Host \$host;
        proxy_set_header X-Real-IP \$remote_addr;
        proxy_set_header X-Forwarded-For \$proxy_add_x_forwarded_for;
        proxy_set_header X-Forwarded-Proto \$scheme;
        proxy_set_header X-Forwarded-Host \$host;
        proxy_set_header X-Forwarded-Port 80;
        proxy_read_timeout 120s;
        proxy_send_timeout 120s;
    }
}
EOF

sudo -n install -m 644 "$TMP_CONFIG" "$SITE_AVAILABLE"
sudo -n ln -sfn "$SITE_AVAILABLE" "$SITE_ENABLED"
sudo -n nginx -t
sudo -n systemctl reload nginx

# Do not use `nginx -T | grep -q` under pipefail: grep exits as soon as it finds
# the match, nginx can receive SIGPIPE, and the successful validation is then
# reported as a failed pipeline. Capture the dump first and inspect it separately.
NGINX_CONFIG_DUMP="$(sudo -n nginx -T 2>&1)"
if ! grep -Fq "server_name $DOMAIN *.$DOMAIN;" <<< "$NGINX_CONFIG_DUMP"; then
  echo "Host nginx did not load the $DOMAIN wildcard vhost." >&2
  exit 52
fi

if ! grep -Fq "location ^~ /.well-known/acme-challenge/" <<< "$NGINX_CONFIG_DUMP"; then
  echo "Host nginx did not load the ACME challenge route for $DOMAIN." >&2
  exit 53
fi

echo "HTTP bootstrap route loaded for $DOMAIN and *.$DOMAIN -> $UPSTREAM with ACME challenge support."
