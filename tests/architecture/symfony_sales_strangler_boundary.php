<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);
$controller = (string) file_get_contents($root . '/symfony/src/Http/Api/V1/Controller/SalesClientCaseStatsController.php');
$handler = (string) file_get_contents($root . '/symfony/src/Application/Sales/Query/GetClientCaseStatsQueryHandler.php');
$factory = (string) file_get_contents($root . '/app/Domains/Sales/Infrastructure/ReadModel/MySql/MysqlClientCaseReadModelFactory.php');
$services = (string) file_get_contents($root . '/symfony/config/services.yaml');
$routes = (string) file_get_contents($root . '/symfony/config/routes.yaml');

foreach (['PDO', 'SELECT ', 'INSERT ', 'UPDATE ', 'DELETE ', 'Infrastructure\\', 'Domains\\', 'OpenAI', 'Telegram'] as $needle) {
    if (str_contains($controller, $needle)) {
        throw new RuntimeException('Sales API controller crossed its transport boundary: ' . $needle);
    }
}
foreach (['Symfony\\', 'PDO', 'SELECT ', 'legacy_cos.pdo'] as $needle) {
    if (str_contains($handler, $needle)) {
        throw new RuntimeException('Sales application query handler crossed its boundary: ' . $needle);
    }
}

if (!str_contains($factory, 'ClientCaseReadModelFactoryInterface') || !str_contains($factory, 'MysqlClientCaseReadModel')) {
    throw new RuntimeException('Sales compatibility factory must adapt the canonical ClientCase read model.');
}
if (!str_contains($services, "Domains\\Sales\\Infrastructure\\ReadModel\\MySql\\MysqlClientCaseReadModelFactory:")
    || !str_contains($services, "$connection: '@legacy_cos.pdo'")) {
    throw new RuntimeException('Symfony composition must bind the Sales read-model factory explicitly to legacy read-only MySQL.');
}
if (!str_contains($routes, 'path: /api/v1/sales/client-cases/stats')) {
    throw new RuntimeException('Versioned Sales strangler route is missing.');
}

echo "Sales strangler boundaries passed.\n";
