#!/usr/bin/env bash
set -Eeuo pipefail

DOMAIN="${1:-company-os.shop}"
UPSTREAM="${2:-127.0.0.1:8080}"
SYMFONY_UPSTREAM="${3:-127.0.0.1:8081}"
PUBLIC_STATIC_ROOT="${COS_PUBLIC_STATIC_ROOT:-/var/www/company-os}"
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

sudo -n install -d -m 755 /etc/nginx/sites-available /etc/nginx/sites-enabled "$ACME_ROOT" "$PUBLIC_STATIC_ROOT"

# Bootstrap the apex certificate only when it is absent. HTTP-01 deliberately
# covers the exact domain, not *.$DOMAIN; wildcard certificates require DNS-01.
if ! sudo -n test -r "$CERT_DIR/fullchain.pem" || ! sudo -n test -r "$CERT_DIR/privkey.pem"; then
  if ! command -v certbot >/dev/null 2>&1; then
    echo "Certbot is required to issue the Let's Encrypt certificate for $DOMAIN." >&2
    exit 41
  fi

  echo "Let's Encrypt certificate for $DOMAIN is missing; requesting it with HTTP-01."
  sudo -n certbot certonly \
    --webroot \
    --webroot-path "$ACME_ROOT" \
    --domain "$DOMAIN" \
    --non-interactive \
    --agree-tos \
    --register-unsafely-without-email \
    --keep-until-expiring
fi

if ! sudo -n test -r "$CERT_DIR/fullchain.pem" || ! sudo -n test -r "$CERT_DIR/privkey.pem"; then
  echo "Let's Encrypt certificate for $DOMAIN is still missing after Certbot." >&2
  exit 41
fi

TMP_CONFIG="$(mktemp)"
TMP_RELOAD_HOOK="$(mktemp)"
trap 'rm -f "$TMP_CONFIG" "$TMP_RELOAD_HOOK"' EXIT

cat > "$TMP_CONFIG" <<EOF
# Apex HTTP is used for ACME and redirects application traffic to HTTPS.
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

# Keep wildcard tenants reachable over HTTP until a DNS-01 wildcard certificate
# is provisioned. Do not redirect them to an apex-only TLS certificate.
server {
    listen 80;
    listen [::]:80;
    server_name *.$DOMAIN;

    client_max_body_size 100m;

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

    # Public analytics and signed content ingress are canonical on Symfony.
    location = /analytics/track {
        proxy_pass http://$SYMFONY_UPSTREAM;
        proxy_http_version 1.1;
        proxy_set_header Host \$host;
        proxy_set_header X-Real-IP \$remote_addr;
        proxy_set_header X-Forwarded-For \$proxy_add_x_forwarded_for;
        proxy_set_header X-Forwarded-Proto \$scheme;
        proxy_set_header X-Forwarded-Host \$host;
    }

    location = /webhooks/n8n/content {
        proxy_pass http://$SYMFONY_UPSTREAM;
        proxy_http_version 1.1;
        proxy_set_header Host \$host;
        proxy_set_header X-Real-IP \$remote_addr;
        proxy_set_header X-Forwarded-For \$proxy_add_x_forwarded_for;
        proxy_set_header X-Forwarded-Proto \$scheme;
        proxy_set_header X-Forwarded-Host \$host;
    }

    # Public Blog / Guide SSR is canonical on Symfony.
    location = /blog {
        proxy_pass http://$SYMFONY_UPSTREAM;
        proxy_http_version 1.1;
        proxy_set_header Host \$host;
        proxy_set_header X-Real-IP \$remote_addr;
        proxy_set_header X-Forwarded-For \$proxy_add_x_forwarded_for;
        proxy_set_header X-Forwarded-Proto \$scheme;
        proxy_set_header X-Forwarded-Host \$host;
    }

    location ^~ /blog/ {
        proxy_pass http://$SYMFONY_UPSTREAM;
        proxy_http_version 1.1;
        proxy_set_header Host \$host;
        proxy_set_header X-Real-IP \$remote_addr;
        proxy_set_header X-Forwarded-For \$proxy_add_x_forwarded_for;
        proxy_set_header X-Forwarded-Proto \$scheme;
        proxy_set_header X-Forwarded-Host \$host;
    }

    location ^~ /guide/ {
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
        proxy_set_header Host \$host;
        proxy_set_header X-Real-IP \$remote_addr;
        proxy_set_header X-Forwarded-For \$proxy_add_x_forwarded_for;
        proxy_set_header X-Forwarded-Proto \$scheme;
        proxy_set_header X-Forwarded-Host \$host;
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

    # Documentation is a deployment artifact, not an application route.
    # Serve it directly from the host so PHP/Phalcon/container routing cannot
    # turn a static documentation failure into an application 403/500.
    location = /docs {
        return 301 /docs/;
    }

    location ^~ /docs/ {
        root $PUBLIC_STATIC_ROOT;
        index index.html;
        try_files \$uri \$uri/ =404;
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

    # Public analytics and signed content ingress are canonical on Symfony.
    location = /analytics/track {
        proxy_pass http://$SYMFONY_UPSTREAM;
        proxy_http_version 1.1;
        proxy_set_header Host \$host;
        proxy_set_header X-Real-IP \$remote_addr;
        proxy_set_header X-Forwarded-For \$proxy_add_x_forwarded_for;
        proxy_set_header X-Forwarded-Proto \$scheme;
        proxy_set_header X-Forwarded-Host \$host;
    }

    location = /webhooks/n8n/content {
        proxy_pass http://$SYMFONY_UPSTREAM;
        proxy_http_version 1.1;
        proxy_set_header Host \$host;
        proxy_set_header X-Real-IP \$remote_addr;
        proxy_set_header X-Forwarded-For \$proxy_add_x_forwarded_for;
        proxy_set_header X-Forwarded-Proto \$scheme;
        proxy_set_header X-Forwarded-Host \$host;
    }

    # Public Blog / Guide SSR is canonical on Symfony.
    location = /blog {
        proxy_pass http://$SYMFONY_UPSTREAM;
        proxy_http_version 1.1;
        proxy_set_header Host \$host;
        proxy_set_header X-Real-IP \$remote_addr;
        proxy_set_header X-Forwarded-For \$proxy_add_x_forwarded_for;
        proxy_set_header X-Forwarded-Proto \$scheme;
        proxy_set_header X-Forwarded-Host \$host;
    }

    location ^~ /blog/ {
        proxy_pass http://$SYMFONY_UPSTREAM;
        proxy_http_version 1.1;
        proxy_set_header Host \$host;
        proxy_set_header X-Real-IP \$remote_addr;
        proxy_set_header X-Forwarded-For \$proxy_add_x_forwarded_for;
        proxy_set_header X-Forwarded-Proto \$scheme;
        proxy_set_header X-Forwarded-Host \$host;
    }

    location ^~ /guide/ {
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
        proxy_set_header Host \$host;
        proxy_set_header X-Real-IP \$remote_addr;
        proxy_set_header X-Forwarded-For \$proxy_add_x_forwarded_for;
        proxy_set_header X-Forwarded-Proto https;
        proxy_set_header X-Forwarded-Host \$host;
        proxy_set_header X-Forwarded-Port 443;
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

# Certbot renewals replace certificate files but nginx must reload to pick them up.
cat > "$TMP_RELOAD_HOOK" <<'EOF'
#!/usr/bin/env bash
set -e
systemctl reload nginx
EOF
sudo -n install -d -m 755 /etc/letsencrypt/renewal-hooks/deploy
sudo -n install -m 755 "$TMP_RELOAD_HOOK" /etc/letsencrypt/renewal-hooks/deploy/reload-nginx.sh

for _ in $(seq 1 15); do
  if curl --fail --silent --show-error \
      --resolve "$DOMAIN:443:127.0.0.1" \
      "https://$DOMAIN/cos" > /dev/null; then
    if sudo -n test -r "$PUBLIC_STATIC_ROOT/docs/index.html"; then
      if ! curl --fail --silent --show-error \
          --resolve "$DOMAIN:443:127.0.0.1" \
          "https://$DOMAIN/docs/" > /dev/null; then
        sleep 2
        continue
      fi
    fi

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
