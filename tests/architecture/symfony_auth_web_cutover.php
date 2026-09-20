<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);
$routes = (string) file_get_contents($root . '/symfony/config/routes.yaml');
$framework = (string) file_get_contents($root . '/symfony/config/packages/framework.yaml');
$compose = (string) file_get_contents($root . '/docker-compose.symfony.yml');
$http = (string) file_get_contents($root . '/deploy/configure-company-os-http.sh');
$tls = (string) file_get_contents($root . '/deploy/configure-dev-tls.sh');
$core = (string) file_get_contents($root . '/app/Interfaces/Web/Routing/CoreWebRoutes.php');

foreach ([
    'cos_web_auth_login:',
    'cos_web_auth_register:',
    'cos_web_auth_logout:',
    'App\\Web\\Auth\\AuthPageController',
] as $needle) {
    if (!str_contains($routes, $needle)) {
        throw new RuntimeException('Symfony Auth route contract missing: ' . $needle);
    }
}

foreach (["session:", "save_path: '%env(LEGACY_SESSION_SAVE_PATH)%'"] as $needle) {
    if (!str_contains($framework, $needle)) {
        throw new RuntimeException('Transitional Symfony session contract missing: ' . $needle);
    }
}

if (str_contains($compose, 'legacy_php_sessions:/var/www/html/legacy-sessions:ro')) {
    throw new RuntimeException('Symfony Auth cannot establish a compatibility session on a read-only volume.');
}
if (!str_contains($compose, 'legacy_php_sessions:/var/www/html/legacy-sessions')) {
    throw new RuntimeException('Transitional shared session volume is missing before Cabinet cutover.');
}
foreach (['session-init:', 'chmod 1777 /sessions', 'service_completed_successfully'] as $needle) {
    if (!str_contains($compose, $needle)) {
        throw new RuntimeException('Writable transition session contract missing: ' . $needle);
    }
}
$services = (string) file_get_contents($root . '/symfony/config/services.yaml');
if (str_contains($services, "App\\Application\\Identity\\Service\\AccountAuthenticationService:\n    arguments:\n      \$connection: '@legacy_cos.pdo'")) {
    throw new RuntimeException('Auth cutover introduced a forbidden direct legacy_cos.pdo dependency.');
}
if (!str_contains($services, "\$database: '@Infrastructure\\Platform\\Persistence\\Pdo\\PdoConnection'")) {
    throw new RuntimeException('Auth service is not bound through the existing PDO compatibility boundary.');
}

foreach ([$http, $tls] as $proxy) {
    if (!str_contains($proxy, 'location ^~ /auth/ {')
        || !str_contains($proxy, 'proxy_pass http://$SYMFONY_UPSTREAM;')) {
        throw new RuntimeException('Host proxy does not route Auth Web to Symfony.');
    }
}

foreach (['/auth/login', '/auth/register', '/auth/logout'] as $path) {
    if (str_contains($core, "'" . $path . "'")) {
        throw new RuntimeException('Phalcon still owns retired Auth route: ' . $path);
    }
}
if (file_exists($root . '/app/Interfaces/Web/Controller/AuthController.php')) {
    throw new RuntimeException('Retired Phalcon AuthController still exists.');
}

echo "Symfony Auth Web cutover boundary passed.\n";
