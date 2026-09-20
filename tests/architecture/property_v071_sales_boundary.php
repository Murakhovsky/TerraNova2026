<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);
$assert = static function (bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
};

$manifest = require $root . '/app/Domains/Property/module.php';
$assert(version_compare((string) ($manifest['version'] ?? '0.0.0'), '0.7.1', '>='), 'Property manifest must be at least V0.7.1.');

$contract = file_get_contents($root . '/app/Domains/Property/Contract/PropertyReferencePort.php') ?: '';
foreach (['getPropertyReference','getInventorySnapshot','findAvailableInventory','getPropertyPresentation','searchPropertyReferences'] as $method) {
    $assert(str_contains($contract, $method), 'PropertyReferencePort missing method: ' . $method);
}

$adapter = file_get_contents($root . '/app/Domains/Sales/Infrastructure/Property/SalesPropertyReference.php') ?: '';
$assert(str_contains($adapter, 'PropertyReferencePort'), 'Sales Property adapter must depend on PropertyReferencePort.');
$assert(str_contains($adapter, 'getPropertyPresentation'), 'Sales Property adapter must resolve presentation through the Property contract.');

$directory = new RecursiveDirectoryIterator($root . '/app/Domains/Sales', FilesystemIterator::SKIP_DOTS);
$iterator = new RecursiveIteratorIterator($directory);
foreach ($iterator as $file) {
    if (!$file->isFile() || $file->getExtension() !== 'php') continue;
    $content = file_get_contents($file->getPathname()) ?: '';
    $assert(!str_contains($content, 'tn_properties'), 'Sales must not directly read retired Property projection storage: ' . $file->getPathname());
}

$factory = file_get_contents($root . '/app/Domains/Sales/Infrastructure/ReadModel/MySql/MysqlClientCaseReadModelFactory.php') ?: '';
foreach (['PropertyReferencePort', 'new SalesPropertyReference', '$organizationId'] as $needle) {
    $assert(str_contains($factory, $needle), 'Sales read-model factory lost Property boundary wiring: ' . $needle);
}

$readModel = file_get_contents($root . '/app/Domains/Sales/Infrastructure/ReadModel/MySql/MysqlClientCaseReadModel.php') ?: '';
$agent = file_get_contents($root . '/app/Domains/Sales/Infrastructure/Persistence/MySql/MysqlSalesAgentContextBuilder.php') ?: '';
$assert(str_contains($readModel, 'SalesPropertyReference'), 'Sales request read model must use the Sales-side Property adapter.');
$assert(str_contains($agent, 'PropertyReferencePort'), 'Sales agent runtime must depend on the tenant-explicit Property contract.');
$assert(str_contains($agent, '$invocation->organizationId'), 'Sales agent runtime must scope Property reads from AgentInvocation.');
$assert(str_contains($agent, 'new SalesPropertyReference'), 'Sales agent runtime must bind Property reference to the invocation tenant.');

$services = file_get_contents($root . '/symfony/config/services.yaml') ?: '';
foreach ([
    'Domains\\Property\\Contract\\PropertyReferencePort:',
    'Domains\\Sales\\Infrastructure\\ReadModel\\MySql\\MysqlClientCaseReadModelFactory:',
    'Domains\\Sales\\Infrastructure\\Persistence\\MySql\\MysqlSalesAgentContextBuilder:',
    "\$properties: '@Domains\\Property\\Contract\\PropertyReferencePort'",
] as $needle) {
    $assert(str_contains($services, $needle), 'Symfony composition is missing Property/Sales boundary wiring: ' . $needle);
}
$assert(!is_file($root . '/app/Bootstrap/SalesServices.php'), 'Retired Sales bootstrap must remain deleted.');

echo "Property V0.7.1 Sales boundary: OK\n";
