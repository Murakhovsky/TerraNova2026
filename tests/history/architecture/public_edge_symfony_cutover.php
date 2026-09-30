<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);
$read = static fn(string $path): string => (string) file_get_contents($root . '/' . $path);
$assert = static function(bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
};

$routes = $read('symfony/config/routes.yaml');
foreach ([
    'cos_web_public_analytics:', 'path: /analytics/track',
    'cos_web_n8n_content_webhook:', 'path: /webhooks/n8n/content',
    'App\\Web\\PublicEdge\\PublicEdgeController',
] as $needle) {
    $assert(str_contains($routes, $needle), 'Symfony public edge route missing: ' . $needle);
}

$controller = $read('symfony/src/Web/PublicEdge/PublicEdgeController.php');
foreach ([
    'PropertyFunnelAnalyticsInterface',
    'InboundContentWebhookInterface',
    'LegacySessionReader',
    'sameOrigin',
    'recordPublicEvent',
    'contentWebhook->handle',
] as $needle) {
    $assert(str_contains($controller, $needle), 'Symfony public edge controller contract missing: ' . $needle);
}

$services = $read('symfony/config/services.yaml');
foreach ([
    'Domains\\Property\\Application\\Contract\\PropertyFunnelAnalyticsInterface:',
    'Infrastructure\\Platform\\Analytics\\MysqlPropertyFunnelAnalytics',
    'Domains\\Content\\Application\\Contract\\InboundContentWebhookInterface:',
    'Infrastructure\\Integration\\N8n\\N8nWebhookService',
    "env(N8N_WEBHOOK_SECRET): ''",
] as $needle) {
    $assert(str_contains($services, $needle), 'Symfony public edge service wiring missing: ' . $needle);
}

$legacyRoutes = $read('app/Interfaces/Web/Routing/FrontendRoutes.php');
foreach (["'/analytics/track'", "'/webhooks/n8n/content'"] as $needle) {
    $assert(!str_contains($legacyRoutes, $needle), 'Phalcon still owns public edge route: ' . $needle);
}
foreach (['AnalyticsController.php', 'N8nWebhookController.php'] as $file) {
    $assert(!is_file($root . '/app/Interfaces/Web/Controller/' . $file), 'Retired Phalcon controller was restored: ' . $file);
}

$compose = $read('docker-compose.symfony.yml');
$assert(str_contains($compose, 'N8N_WEBHOOK_SECRET: ${N8N_WEBHOOK_SECRET:-}'), 'Symfony runtime does not receive N8N_WEBHOOK_SECRET.');

foreach (['deploy/configure-company-os-http.sh', 'deploy/configure-dev-tls.sh'] as $path) {
    $proxy = $read($path);
    foreach (['location = /analytics/track {', 'location = /webhooks/n8n/content {'] as $needle) {
        $assert(str_contains($proxy, $needle), 'Host proxy public edge ownership missing in ' . $path . ': ' . $needle);
    }
}

echo "Symfony public edge cutover boundary OK\n";
