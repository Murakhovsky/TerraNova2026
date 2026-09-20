#!/usr/bin/env bash
set -Eeuo pipefail

DOMAIN="${1:-company-os.shop}"
UPSTREAM="${2:-127.0.0.1:8080}"
SYMFONY_UPSTREAM="${3:-127.0.0.1:8081}"
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

    # Public SEO metadata is canonical on Symfony.
    location = /robots.txt {
        proxy_pass http://$SYMFONY_UPSTREAM;
        proxy_http_version 1.1;
        proxy_set_header Host \$host;
        proxy_set_header X-Real-IP \$remote_addr;
        proxy_set_header X-Forwarded-For \$proxy_add_x_forwarded_for;
        proxy_set_header X-Forwarded-Proto \$scheme;
        proxy_set_header X-Forwarded-Host \$host;
    }

    location = /sitemap.xml {
        proxy_pass http://$SYMFONY_UPSTREAM;
        proxy_http_version 1.1;
        proxy_set_header Host \$host;
        proxy_set_header X-Real-IP \$remote_addr;
        proxy_set_header X-Forwarded-For \$proxy_add_x_forwarded_for;
        proxy_set_header X-Forwarded-Proto \$scheme;
        proxy_set_header X-Forwarded-Host \$host;
    }

    # Sales SSR and immutable frontend assets are canonical on Symfony.
    location = /sales {
        proxy_pass http://$SYMFONY_UPSTREAM;
        proxy_http_version 1.1;
        proxy_set_header Host \$host;
        proxy_set_header X-Real-IP \$remote_addr;
        proxy_set_header X-Forwarded-For \$proxy_add_x_forwarded_for;
        proxy_set_header X-Forwarded-Proto \$scheme;
        proxy_set_header X-Forwarded-Host \$host;
    }

    location ^~ /sales/ {
        proxy_pass http://$SYMFONY_UPSTREAM;
        proxy_http_version 1.1;
        proxy_set_header Host \$host;
        proxy_set_header X-Real-IP \$remote_addr;
        proxy_set_header X-Forwarded-For \$proxy_add_x_forwarded_for;
        proxy_set_header X-Forwarded-Proto \$scheme;
        proxy_set_header X-Forwarded-Host \$host;
    }

    location ^~ /build/ {
        proxy_pass http://$SYMFONY_UPSTREAM;
        proxy_http_version 1.1;
        proxy_set_header Host \$host;
        proxy_set_header X-Forwarded-Proto \$scheme;
    }

    # Visualization and Diagnostic SSR are canonical on Symfony.
    location = /cos/architecture {
        proxy_pass http://$SYMFONY_UPSTREAM;
        proxy_http_version 1.1;
        proxy_set_header Host \$host;
        proxy_set_header X-Real-IP \$remote_addr;
        proxy_set_header X-Forwarded-For \$proxy_add_x_forwarded_for;
        proxy_set_header X-Forwarded-Proto \$scheme;
        proxy_set_header X-Forwarded-Host \$host;
    }

    location ^~ /cos/architecture/ {
        proxy_pass http://$SYMFONY_UPSTREAM;
        proxy_http_version 1.1;
        proxy_set_header Host \$host;
        proxy_set_header X-Real-IP \$remote_addr;
        proxy_set_header X-Forwarded-For \$proxy_add_x_forwarded_for;
        proxy_set_header X-Forwarded-Proto \$scheme;
        proxy_set_header X-Forwarded-Host \$host;
    }

    location ^~ /admin/diagnostics/ {
        proxy_pass http://$SYMFONY_UPSTREAM;
        proxy_http_version 1.1;
        proxy_set_header Host \$host;
        proxy_set_header X-Real-IP \$remote_addr;
        proxy_set_header X-Forwarded-For \$proxy_add_x_forwarded_for;
        proxy_set_header X-Forwarded-Proto \$scheme;
        proxy_set_header X-Forwarded-Host \$host;
    }

    location ^~ /diagnostics/ {
        proxy_pass http://$SYMFONY_UPSTREAM;
        proxy_http_version 1.1;
        proxy_set_header Host \$host;
        proxy_set_header X-Real-IP \$remote_addr;
        proxy_set_header X-Forwarded-For \$proxy_add_x_forwarded_for;
        proxy_set_header X-Forwarded-Proto \$scheme;
        proxy_set_header X-Forwarded-Host \$host;
    }
    # Spatial Web workspace and public viewer are canonical on Symfony.
    location ^~ /spatial/ {
        client_max_body_size 220m;
        proxy_pass http://$SYMFONY_UPSTREAM;
        proxy_http_version 1.1;
        proxy_set_header Host \$host;
        proxy_set_header X-Real-IP \$remote_addr;
        proxy_set_header X-Forwarded-For \$proxy_add_x_forwarded_for;
        proxy_set_header X-Forwarded-Proto \$scheme;
        proxy_set_header X-Forwarded-Host \$host;
        proxy_read_timeout 300s;
        proxy_send_timeout 300s;
    }
    # Canonical COS business/control-plane APIs are served by Symfony.
    location ^~ /api/v1/ {
        proxy_pass http://$SYMFONY_UPSTREAM;
        proxy_http_version 1.1;
        proxy_set_header Host $host;
        proxy_set_header X-Real-IP $remote_addr;
        proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;
        proxy_set_header X-Forwarded-Proto $scheme;
        proxy_set_header X-Forwarded-Host $host;
        proxy_set_header X-Forwarded-Port 80;
        proxy_read_timeout 120s;
        proxy_send_timeout 120s;
    }

    # Spatial API and assets are served by Symfony after cutover.
    location ^~ /api/spatial/ {
        client_max_body_size 220m;
        proxy_pass http://$SYMFONY_UPSTREAM;
        proxy_http_version 1.1;
        proxy_set_header Host \$host;
        proxy_set_header X-Real-IP \$remote_addr;
        proxy_set_header X-Forwarded-For \$proxy_add_x_forwarded_for;
        proxy_set_header X-Forwarded-Proto \$scheme;
        proxy_read_timeout 300s;
        proxy_send_timeout 300s;
    }

    location ^~ /uploads/spatial/ {
        proxy_pass http://$SYMFONY_UPSTREAM;
        proxy_http_version 1.1;
        proxy_set_header Host \$host;
        proxy_set_header X-Forwarded-Proto \$scheme;
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

echo "HTTP bootstrap route loaded: compatibility Web -> $UPSTREAM; canonical APIs, Sales, Visualization and Diagnostics -> $SYMFONY_UPSTREAM."
