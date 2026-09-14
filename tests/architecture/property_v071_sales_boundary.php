<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);
$assert = static function (bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
};

$manifest = require $root . '/app/Domains/Property/module.php';
$assert(version_compare((string) ($manifest['version'] ?? '0.0.0'), '0.7.1', '>='), 'Property manifest must be at least V0.7.1.');
$assert((string) ($manifest['schema_version'] ?? '') === '0.7.0', 'Boundary hardening must not invent a schema migration.');

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
    $assert(!str_contains($content, 'tn_properties'), 'Sales must not directly read canonical/legacy Property storage: ' . $file->getPathname());
}

$services = file_get_contents($root . '/app/Bootstrap/SalesServices.php') ?: '';
foreach (["'salesPropertyReference'", "'propertyReferencePort'", "'salesClientCaseReadModel'", "'salesClientCaseCommands'", "'salesAgentContextBuilder'"] as $needle) {
    $assert(str_contains($services, $needle), 'Sales composition root is missing Property boundary wiring: ' . $needle);
}

$readModel = file_get_contents($root . '/app/Domains/Sales/Infrastructure/ReadModel/MySql/MysqlClientCaseReadModel.php') ?: '';
$commands = file_get_contents($root . '/app/Domains/Sales/Infrastructure/Persistence/MySql/MysqlClientCaseCommandRepository.php') ?: '';
$agent = file_get_contents($root . '/app/Domains/Sales/Infrastructure/Persistence/MySql/MysqlSalesAgentContextBuilder.php') ?: '';
foreach ([$readModel, $commands, $agent] as $content) {
    $assert(str_contains($content, 'SalesPropertyReference'), 'Every Sales property consumer must use the Sales-side Property adapter.');
}

echo "Property V0.7.1 Sales boundary: OK\n";
