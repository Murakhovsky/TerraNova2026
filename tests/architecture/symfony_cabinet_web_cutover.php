<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);
$routes = (string) file_get_contents($root . '/symfony/config/routes.yaml');
$security = (string) file_get_contents($root . '/symfony/config/packages/security.yaml');
$authenticator = (string) file_get_contents($root . '/symfony/src/Security/LegacySessionAuthenticator.php');
$services = (string) file_get_contents($root . '/symfony/config/services.yaml');
$core = (string) file_get_contents($root . '/app/Interfaces/Web/Routing/CoreWebRoutes.php');

foreach ([
    'cos_web_cabinet:',
    'path: /cabinet',
    'App\\Web\\Cabinet\\CabinetPageController::index',
    'cos_web_cabinet_submission:',
    'path: /cabinet/submission/{id}',
    'App\\Web\\Cabinet\\CabinetPageController::submission',
] as $needle) {
    if (!str_contains($routes, $needle)) {
        throw new RuntimeException('Symfony Cabinet route contract missing: ' . $needle);
    }
}

if (!str_contains($security, '|cabinet|')
    || !str_contains($security, "path: '^/cabinet(?:/|$)'")) {
    throw new RuntimeException('Cabinet is not protected by the Symfony security boundary.');
}
if (!str_contains($authenticator, "str_starts_with($path, '/cabinet')")) {
    throw new RuntimeException('Transition authenticator does not recognize Cabinet Web requests.');
}

if (str_contains($services, "App\\Application\\Identity\\Service\\CabinetPortalService:\n    arguments:\n      \$connection: '@legacy_cos.pdo'")) {
    throw new RuntimeException('CabinetPortalService must not add a direct legacy DB dependency.');
}
if (!str_contains($services, "App\\Application\\Identity\\Service\\CabinetPortalService:\n    arguments:\n      \$database: '@Infrastructure\\Platform\\Persistence\\Pdo\\PdoConnection'")) {
    throw new RuntimeException('CabinetPortalService must reuse the frozen PDO compatibility boundary.');
}

foreach ([
    'CabinetPortalService:',
    'PropertySubmissionInterface:',
    'MysqlPropertySubmissionRepository:',
    'MediaStorageService:',
] as $needle) {
    if (!str_contains($services, $needle)) {
        throw new RuntimeException('Cabinet application wiring missing: ' . $needle);
    }
}

if (file_exists($root . '/app/Interfaces/Web/Controller/CabinetController.php')) {
    throw new RuntimeException('Retired Phalcon CabinetController still exists.');
}
foreach (['/cabinet', '/cabinet/submission/'] as $path) {
    if (str_contains($core, "'" . $path)) {
        throw new RuntimeException('Phalcon still owns retired Cabinet route: ' . $path);
    }
}

foreach (['deploy/configure-company-os-http.sh', 'deploy/configure-dev-tls.sh'] as $path) {
    $proxy = (string) file_get_contents($root . '/' . $path);
    if (!str_contains($proxy, 'location = /cabinet {')
        || !str_contains($proxy, 'location ^~ /cabinet/ {')) {
        throw new RuntimeException('Host proxy does not route Cabinet to Symfony: ' . $path);
    }
}

echo "Symfony Cabinet Web cutover boundary passed.\n";
