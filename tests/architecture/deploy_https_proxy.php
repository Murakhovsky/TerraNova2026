<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);
$dockerNginx = (string) file_get_contents($root . '/docker/nginx/default.conf');
$dockerCompose = (string) file_get_contents($root . '/docker-compose.yml');
$dockerfile = (string) file_get_contents($root . '/docker/php/Dockerfile');
$servicesWeb = (string) file_get_contents($root . '/app/config/services_web.php');
$httpBootstrap = (string) file_get_contents($root . '/deploy/configure-company-os-http.sh');
$tls = (string) file_get_contents($root . '/deploy/configure-dev-tls.sh');
$devDeploy = (string) file_get_contents($root . '/deploy/dev.sh');
$symfonyDeploy = (string) file_get_contents($root . '/deploy/symfony-dev.sh');
$symfonyCompose = (string) file_get_contents($root . '/docker-compose.symfony.yml');
$bootstrapWeb = (string) file_get_contents($root . '/app/bootstrap_web.php');
$trustedProxy = (string) file_get_contents($root . '/app/config/trusted_proxy.php');
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

foreach ([
    'exec "$NGINX_ID" nginx -t',
    'exec "$NGINX_ID" nginx -s reload',
    'Nginx container configuration validated and reloaded.',
] as $needle) {
    if (!str_contains($devDeploy, $needle)) {
        throw new RuntimeException('Container nginx reload contract is missing: ' . $needle);
    }
}

if (!str_contains($bootstrapWeb, "require APP_PATH . '/config/trusted_proxy.php';")) {
    throw new RuntimeException('Web bootstrap must normalize trusted proxy request metadata before DI services are created.');
}

foreach ([
    'HTTP_X_FORWARDED_PROTO',
    "\$server['HTTPS'] = 'on';",
    "\$server['REQUEST_SCHEME'] = \$scheme;",
    "\$server['SERVER_PORT'] = '443';",
] as $needle) {
    if (!str_contains($trustedProxy, $needle)) {
        throw new RuntimeException('Application trusted-proxy normalization contract is missing: ' . $needle);
    }
}

foreach ([
    "'lifetime' => \$sessionLifetime",
    "ini_set('session.gc_maxlifetime', (string) \$sessionLifetime);",
    "'savePath' => \$sessionPath",
    "COS_SESSION_LIFETIME_SECONDS",
] as $needle) {
    if (!str_contains($servicesWeb, $needle)) {
        throw new RuntimeException('Persistent web-session contract is missing: ' . $needle);
    }
}

foreach ([
    'COS_SESSION_LIFETIME_SECONDS: ${COS_SESSION_LIFETIME_SECONDS:-2592000}',
    'COS_SESSION_SAVE_PATH: /var/www/html/tmp/sessions',
    'php_sessions:/var/www/html/tmp/sessions',
    'php_sessions:',
] as $needle) {
    if (!str_contains($dockerCompose, $needle)) {
        throw new RuntimeException('Persistent Docker session-volume contract is missing: ' . $needle);
    }
}

if (!str_contains($dockerfile, '/var/www/html/tmp/sessions')) {
    throw new RuntimeException('PHP image must prepare the persistent session mount point.');
}

require $root . '/app/config/trusted_proxy.php';
$proxiedHttps = [
    'HTTP_X_FORWARDED_PROTO' => 'https',
    'HTTPS' => 'off',
    'REQUEST_SCHEME' => 'http',
    'SERVER_PORT' => '80',
];
normalizeTrustedProxyRequest($proxiedHttps);
if (($proxiedHttps['HTTPS'] ?? null) !== 'on'
    || ($proxiedHttps['REQUEST_SCHEME'] ?? null) !== 'https'
    || ($proxiedHttps['SERVER_PORT'] ?? null) !== '443') {
    throw new RuntimeException('Trusted HTTPS proxy metadata is not normalized for the PHP runtime.');
}

foreach ([$httpBootstrap, $tls] as $proxyConfig) {
    foreach ([
        'SYMFONY_UPSTREAM="${3:-127.0.0.1:8081}"',
        'location ^~ /api/v1/',
        'proxy_pass http://$SYMFONY_UPSTREAM;',
    ] as $needle) {
        if (!str_contains($proxyConfig, $needle)) {
            throw new RuntimeException('Canonical Symfony API reverse-proxy contract is missing: ' . $needle);
        }
    }
}

if (!str_contains($devDeploy, 'bash deploy/symfony-dev.sh')
    || !str_contains($devDeploy, 'http://127.0.0.1:8081/api/v1/health')) {
    throw new RuntimeException('DEV deployment does not boot and verify the canonical Symfony API runtime.');
}

foreach ([
    'SYMFONY_LEGACY_DB_USER "cos_symfony_app"',
    'GRANT SELECT, INSERT, UPDATE, DELETE ON',
    "'cos_symfony_app'@'%%'",
] as $needle) {
    if (!str_contains($symfonyDeploy, $needle)) {
        throw new RuntimeException('Symfony legacy database DML account contract is missing: ' . $needle);
    }
}
if (!str_contains($symfonyCompose, 'SYMFONY_LEGACY_DB_USER:-cos_symfony_app')) {
    throw new RuntimeException('Symfony compose does not default to the DML application account.');
}

if (!str_contains($workflow, 'bash tests/smoke/https_proxy_contract.sh')) {
    throw new RuntimeException('AWS dev deployment must execute the live HTTPS proxy smoke test.');
}
echo "HTTPS proxy deployment contract passed.\n";
