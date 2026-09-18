<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);
$controller = (string) file_get_contents($root . '/symfony/src/Http/Api/V1/Controller/SalesReadController.php');
$routes = (string) file_get_contents($root . '/symfony/config/routes.yaml');
$services = (string) file_get_contents($root . '/symfony/config/services.yaml');
$interface = (string) file_get_contents($root . '/app/Domains/Sales/Application/Contract/SalesWorkspaceReadModelInterface.php');
$readModel = (string) file_get_contents($root . '/app/Domains/Sales/Infrastructure/ReadModel/MySql/MysqlSalesWorkspaceReadModel.php');

foreach (['PDO', 'SELECT ', 'INSERT ', 'UPDATE ', 'DELETE ', 'legacy_cos.pdo', 'Infrastructure\\'] as $needle) {
    if (str_contains($controller, $needle)) {
        throw new RuntimeException('Wave 1 controller crossed the transport boundary: ' . $needle);
    }
}

$queryDir = $root . '/symfony/src/Application/Sales/Query';
foreach (glob($queryDir . '/*Sales*QueryHandler.php') ?: [] as $file) {
    $source = (string) file_get_contents($file);
    foreach (['Symfony\\', 'PDO', 'SELECT ', 'legacy_cos.pdo', 'Infrastructure\\'] as $needle) {
        if (str_contains($source, $needle)) {
            throw new RuntimeException(basename($file) . ' crossed the Application boundary: ' . $needle);
        }
    }
}

foreach ([
    '/api/v1/sales/dashboard',
    '/api/v1/sales/leads',
    '/api/v1/sales/leads/{id}',
    '/api/v1/sales/opportunities',
    '/api/v1/sales/opportunities/{id}',
    '/api/v1/sales/pipelines',
] as $route) {
    if (!str_contains($routes, 'path: ' . $route)) {
        throw new RuntimeException('Wave 1 route missing: ' . $route);
    }
}

if (!str_contains($interface, 'public function lead(string $organizationId, int $leadId): ?array;')) {
    throw new RuntimeException('Canonical Sales read contract is missing lead detail.');
}
if (!str_contains($services, "Domains\\Sales\\Application\\Contract\\SalesWorkspaceReadModelInterface:")
    || !str_contains($services, "$connection: '@legacy_cos.pdo'")) {
    throw new RuntimeException('Symfony must bind the canonical Sales read model through the legacy Infrastructure adapter only.');
}
foreach (['organization_id = :organization_id', 'ORDER BY ' . "' . " . '$orderBy', 'OFFSET ' . "' . " . '$offset'] as $needle) {
    if (!str_contains($readModel, $needle)) {
        throw new RuntimeException('Sales read model is missing tenant/pagination hardening: ' . $needle);
    }
}

echo "Symfony Sales Wave 1 architecture boundaries passed.\n";
