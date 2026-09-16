<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);
$assert = static function (bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
};

$manifest = require $root . '/app/Domains/Property/module.php';
$assert(version_compare((string) ($manifest['version'] ?? '0.0.0'), '0.7.1', '>='), 'Property manifest must be at least V0.7.1.');
$migrations = $manifest['contributions']['migration_files'] ?? [];
$assert(in_array('app/migrations/20260914_000055_property_v070_history_contracts.sql', $migrations, true), 'V0.7 canonical schema baseline is missing.');
foreach ($migrations as $migration) {
    $assert(!str_contains((string) $migration, 'v071'), 'Boundary hardening V0.7.1 must not invent its own schema migration.');
}

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
foreach ([$readModel, $commands] as $content) {
    $assert(str_contains($content, 'SalesPropertyReference'), 'Request-scoped Sales property consumers must use the Sales-side Property adapter.');
}
$assert(str_contains($agent, 'PropertyReferencePort'), 'Sales agent runtime must depend on the tenant-explicit Property contract.');
$assert(str_contains($agent, '$invocation->organizationId'), 'Sales agent runtime must scope Property reads from AgentInvocation.');
$assert(str_contains($agent, 'new SalesPropertyReference'), 'Sales agent runtime may reuse the Sales Property adapter only with the invocation tenant supplied explicitly.');

$agentRegistrationStart = strpos($services, "$di->setShared('salesAgentContextBuilder'");
$domainRegistrationStart = strpos($services, "$di->setShared('salesDomainModule'");
$assert($agentRegistrationStart !== false && $domainRegistrationStart !== false && $domainRegistrationStart > $agentRegistrationStart, 'Sales agent registration block is missing.');
$agentRegistration = substr($services, $agentRegistrationStart, $domainRegistrationStart - $agentRegistrationStart);
$assert(str_contains($agentRegistration, "getShared('propertyReferencePort')"), 'Sales agent runtime must receive PropertyReferencePort directly.');
$assert(!str_contains($agentRegistration, "getShared('salesPropertyReference')"), 'Sales agent runtime must not inherit request-scoped SalesPropertyReference.');
$assert(!str_contains($agentRegistration, 'organizationContext'), 'Sales agent runtime composition must not resolve request/session organization context.');

echo "Property V0.7.1 Sales boundary: OK\n";
