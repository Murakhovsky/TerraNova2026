<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);
$controller = (string) file_get_contents($root . '/symfony/src/Http/Api/V1/Controller/SalesWriteController.php');
$routes = (string) file_get_contents($root . '/symfony/config/routes.yaml');
$services = (string) file_get_contents($root . '/symfony/config/services.yaml');
$factory = (string) file_get_contents($root . '/app/Domains/Sales/Infrastructure/Persistence/MySql/MysqlSalesWriteServiceFactory.php');
$repository = (string) file_get_contents($root . '/app/Domains/Sales/Infrastructure/Persistence/MySql/MysqlClientCaseCommandRepository.php');

foreach (['PDO', 'SELECT ', 'INSERT ', 'UPDATE ', 'DELETE ', 'legacy_cos.pdo', 'Infrastructure\\'] as $needle) {
    if (str_contains($controller, $needle)) {
        throw new RuntimeException('Wave 2 controller crossed the HTTP boundary: ' . $needle);
    }
}

foreach (glob($root . '/symfony/src/Application/Sales/Command/*Handler.php') ?: [] as $file) {
    $source = (string) file_get_contents($file);
    foreach (['Symfony\\', 'PDO', 'SELECT ', 'INSERT ', 'UPDATE ', 'legacy_cos.pdo', 'Infrastructure\\'] as $needle) {
        if (str_contains($source, $needle)) {
            throw new RuntimeException(basename($file) . ' crossed the Application boundary: ' . $needle);
        }
    }
}

foreach ([
    'methods: [POST]' => '/api/v1/sales/leads',
    'methods: [PATCH]' => '/api/v1/sales/leads/{id}',
    '/api/v1/sales/leads/{id}/opportunity' => null,
    '/api/v1/sales/opportunities/{id}/activities' => null,
    '/api/v1/sales/opportunities/{id}/stage' => null,
    '/api/v1/sales/opportunities/{id}/next-action' => null,
] as $needle => $secondary) {
    if (!str_contains($routes, $needle) || ($secondary !== null && !str_contains($routes, $secondary))) {
        throw new RuntimeException('Wave 2 write route contract is missing: ' . $needle);
    }
}

foreach (['LegacySessionCsrfValidator', 'X-Idempotency-Key', 'CommandBusInterface', '_cos_correlation_id'] as $needle) {
    if (!str_contains($controller, $needle)) {
        throw new RuntimeException('Wave 2 transport guard missing: ' . $needle);
    }
}
if (!str_contains($services, 'SalesWriteServiceFactoryInterface:') || !str_contains($services, 'MysqlSalesWriteServiceFactory')) {
    throw new RuntimeException('Symfony must bind the canonical Sales write factory.');
}
foreach (['MysqlEventStore', 'TransactionManager', 'ChangeDealStage', 'ScheduleDealFollowup', 'SalesInboundService'] as $needle) {
    if (!str_contains($factory, $needle)) {
        throw new RuntimeException('Sales write Infrastructure composition is incomplete: ' . $needle);
    }
}
if (str_contains($repository, 'INSERT INTO sales_deal_stage_history')) {
    throw new RuntimeException('Client Case creation must not write the event-owned historical projection directly.');
}

echo "Symfony Sales Wave 2 architecture boundaries passed.\n";
