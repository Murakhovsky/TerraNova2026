<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);
$dockerNginx = (string) file_get_contents($root . '/docker/nginx/default.conf');
$httpBootstrap = (string) file_get_contents($root . '/deploy/configure-company-os-http.sh');
$tls = (string) file_get_contents($root . '/deploy/configure-dev-tls.sh');
$workflow = (string) file_get_contents($root . '/.github/workflows/diagnostic.yml');

foreach ([
    'map $http_x_forwarded_proto $cos_https',
    'https on;',
    'fastcgi_param HTTPS $cos_https;',
    'fastcgi_param REQUEST_SCHEME $cos_request_scheme;',
    'fastcgi_param SERVER_PORT $cos_server_port;',
    'fastcgi_param HTTP_X_FORWARDED_PROTO $http_x_forwarded_proto;',
    'fastcgi_param HTTP_X_FORWARDED_HOST $http_x_forwarded_host;',
    'fastcgi_param HTTP_X_FORWARDED_PORT $http_x_forwarded_port;',
] as $needle) {
    if (!str_contains($dockerNginx, $needle)) {
        throw new RuntimeException('Docker nginx HTTPS forwarding contract is missing: ' . $needle);
    }
}

foreach ([
    'CERT_DIR="/etc/letsencrypt/live/$DOMAIN"',
    'TLS_LISTEN_PATTERN=',
    'grep -Eq "$TLS_LISTEN_PATTERN" "$SITE_AVAILABLE"',
    'Existing HTTPS vhost preserved for $DOMAIN; HTTP bootstrap skipped.',
] as $needle) {
    if (!str_contains($httpBootstrap, $needle)) {
        throw new RuntimeException('Host nginx TLS-preservation contract is missing: ' . $needle);
    }
}

foreach ([
    'return 301 https://\\$host\\$request_uri;',
    'proxy_set_header X-Forwarded-Proto https;',
    'proxy_set_header X-Forwarded-Port 443;',
] as $needle) {
    if (!str_contains($tls, $needle)) {
        throw new RuntimeException('TLS reverse-proxy contract is missing: ' . $needle);
    }
}

if (!str_contains($workflow, 'bash tests/smoke/https_proxy_contract.sh')) {
    throw new RuntimeException('AWS dev deployment must execute the live HTTPS proxy smoke test.');
}

echo "HTTPS proxy deployment contract passed.\n";
