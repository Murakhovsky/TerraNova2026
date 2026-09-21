<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);

$required = [
    'symfony/src/Web/Experience/Realtime/RealtimeTopic.php',
    'symfony/src/Web/Experience/Realtime/RealtimeTopicFactory.php',
    'symfony/src/Web/Experience/Realtime/RealtimeStreamPublisher.php',
    'symfony/src/Web/Experience/Component/CosRealtimeSubscription.php',
    'symfony/templates/components/experience/cos_realtime_subscription.html.twig',
    'symfony/assets/controllers/realtime_connection_controller.js',
    'symfony/src/Command/RealtimePlatformSmokeCommand.php',
    'symfony/config/packages/mercure.yaml',
];

foreach ($required as $relative) {
    if (!is_file($root . '/' . $relative)) {
        throw new RuntimeException('Wave 12.12 Realtime Platform artifact is missing: ' . $relative);
    }
}

$composer = json_decode(
    (string) file_get_contents($root . '/symfony/composer.json'),
    true,
    flags: JSON_THROW_ON_ERROR,
);
if (($composer['require']['symfony/mercure-bundle'] ?? null) !== '^0.5.0') {
    throw new RuntimeException('MercureBundle must be an explicit canonical Symfony dependency.');
}

$bundles = (string) file_get_contents($root . '/symfony/config/bundles.php');
if (!str_contains($bundles, 'MercureBundle::class')) {
    throw new RuntimeException('MercureBundle is not enabled.');
}

$config = (string) file_get_contents($root . '/symfony/config/packages/mercure.yaml');
foreach (['MERCURE_URL', 'MERCURE_PUBLIC_URL', 'MERCURE_JWT_SECRET', "publish: ['*']"] as $marker) {
    if (!str_contains($config, $marker)) {
        throw new RuntimeException('Mercure configuration is missing: ' . $marker);
    }
}

$topics = (string) file_get_contents($root . '/symfony/src/Web/Experience/Realtime/RealtimeTopicFactory.php');
foreach (['organizations/%s', '/workspaces/%s', '/entities/%s/%s', '/users/%s', 'EntityRef'] as $marker) {
    if (!str_contains($topics, $marker)) {
        throw new RuntimeException('Realtime topic contract is missing: ' . $marker);
    }
}
foreach (['Domains\\', 'Doctrine\\', 'PDO'] as $forbidden) {
    if (str_contains($topics, $forbidden)) {
        throw new RuntimeException('Realtime topic factory leaked business/data dependency: ' . $forbidden);
    }
}

$publisher = (string) file_get_contents($root . '/symfony/src/Web/Experience/Realtime/RealtimeStreamPublisher.php');
foreach (['HubInterface', 'new Update(', '$topic->value', '$html', 'true)', '<turbo-stream'] as $marker) {
    if (!str_contains($publisher, $marker)) {
        throw new RuntimeException('Private realtime publisher contract is missing: ' . $marker);
    }
}
foreach (['Domains\\', 'Doctrine\\', 'PDO', 'Repository'] as $forbidden) {
    if (str_contains($publisher, $forbidden)) {
        throw new RuntimeException('Realtime publisher crossed business/data boundary: ' . $forbidden);
    }
}

$subscription = (string) file_get_contents(
    $root . '/symfony/templates/components/experience/cos_realtime_subscription.html.twig',
);
foreach (['turbo_stream_listen', 'subscribe: topic.value', 'withCredentials: true'] as $marker) {
    if (!str_contains($subscription, $marker)) {
        throw new RuntimeException('Private Turbo Stream subscription is missing: ' . $marker);
    }
}

$controllers = (string) file_get_contents($root . '/symfony/assets/controllers.json');
if (!preg_match('/"mercure-turbo-stream"\s*:\s*\{[^}]*"enabled"\s*:\s*true/s', $controllers)) {
    throw new RuntimeException('Symfony UX Turbo Mercure controller must be enabled for UX 2.x.');
}

$browser = (string) file_get_contents($root . '/symfony/assets/controllers/realtime_connection_controller.js');
foreach (['cos:realtime-update', "'reconnecting'", "'offline'", "'live'"] as $marker) {
    if (!str_contains($browser, $marker)) {
        throw new RuntimeException('Realtime presentation state contract is missing: ' . $marker);
    }
}
foreach (['fetch(', 'axios', 'EventSource(', 'localStorage', 'sessionStorage'] as $forbidden) {
    if (str_contains($browser, $forbidden)) {
        throw new RuntimeException('Realtime presentation controller owns forbidden transport/state: ' . $forbidden);
    }
}

$compose = (string) file_get_contents($root . '/docker-compose.yml');
foreach ([
    'image: dunglas/mercure:v0.24.2',
    'MERCURE_PUBLISHER_JWT_KEY',
    'MERCURE_SUBSCRIBER_JWT_KEY',
    '/mercure/health/ready',
    'mercure_data:',
    'mercure_config:',
] as $marker) {
    if (!str_contains($compose, $marker)) {
        throw new RuntimeException('Mercure Docker runtime is missing: ' . $marker);
    }
}

$deploy = (string) file_get_contents($root . '/deploy/dev.sh');
foreach ([
    'ensure_secret SYMFONY_APP_SECRET',
    'ensure_secret SPATIAL_JWT_SECRET',
    'ensure_secret MERCURE_JWT_SECRET',
    'up -d mysql redis mercure',
    'Mercure failed readiness before canonical HTTP startup.',
    'logs --no-color --tail=250 nginx php mercure mysql redis',
] as $deployMarker) {
    if (!str_contains($deploy, $deployMarker)) {
        throw new RuntimeException('Mercure deployment readiness contract is missing: ' . $deployMarker);
    }
}

if (!preg_match('/nginx:\s.*?depends_on:\s.*?php:\s*condition:\s*service_healthy\s*mercure:\s*condition:\s*service_healthy/s', $compose)) {
    throw new RuntimeException('Nginx must wait for healthy Mercure before startup.');
}

$nginx = (string) file_get_contents($root . '/docker/symfony/nginx/default.conf');
foreach (['location = /.well-known/mercure', 'proxy_pass http://mercure', 'proxy_buffering off', 'X-Accel-Buffering'] as $marker) {
    if (!str_contains($nginx, $marker)) {
        throw new RuntimeException('Mercure same-origin SSE proxy is missing: ' . $marker);
    }
}

$routes = (string) file_get_contents($root . '/symfony/config/routes.yaml');
foreach (['cos_web_realtime_platform_preview:', 'path: /dev/realtime', 'cos_web_realtime_platform_publish:'] as $marker) {
    if (!str_contains($routes, $marker)) {
        throw new RuntimeException('Realtime reference route is missing: ' . $marker);
    }
}

$smoke = (string) file_get_contents($root . '/symfony/src/Command/RealtimePlatformSmokeCommand.php');
if (!str_contains($smoke, "name: 'cos:web:realtime:smoke'")) {
    throw new RuntimeException('Realtime Platform runtime smoke is missing.');
}

echo "Wave 12.12 Realtime Platform passed.\n";
