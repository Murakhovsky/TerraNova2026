#!/usr/bin/env php
<?php
declare(strict_types=1);

use Kernel\Module\CanonicalCapabilityCatalog;
use Kernel\Module\ModuleCatalog;
use Kernel\Module\ModuleDiscovery;

$root = dirname(__DIR__);
require $root . '/vendor/autoload.php';

// Definition-layer census only. Never infer DI/Action handler health from a
// manifest or mark a capability executable for a tenant.
$catalog = new CanonicalCapabilityCatalog(new ModuleCatalog(
    (new ModuleDiscovery($root . '/app/Domains'))->discover()
));
$entries = [];
$counts = [];
foreach ($catalog->declared() as $id => $owner) {
    $contract = $catalog->describe($id);
    $row = [
        'id' => $id,
        'owner_domain' => $owner,
        'status' => 'DECLARATIVE_ONLY',
        'runtime_binding_verified' => false,
        'tenant_authorized' => null,
    ];
    if ($contract !== null) {
        $row['version'] = $contract->version;
        $row['kind'] = $contract->kind;
        $row['binding_kind'] = explode(':', $contract->executionBinding, 2)[0];
        $row['lifecycle'] = $contract->lifecycle;
        try {
            $snapshot = $catalog->snapshot($id, $root);
            $row['status'] = 'SCHEMA_VALID_BINDING_UNVERIFIED';
            $row['contract_digest'] = $snapshot['contract_digest'];
            $row['input_schema_digest'] = $snapshot['input_schema_digest'];
            $row['output_schema_digest'] = $snapshot['output_schema_digest'];
        } catch (\Throwable $exception) {
            $row['status'] = 'SCHEMA_INVALID';
            $row['problem'] = $exception->getMessage();
        }
    }
    $entries[] = $row;
    $counts[$row['status']] = ($counts[$row['status']] ?? 0) + 1;
}
ksort($counts);
$report = [
    'schema_version' => '1.0.0',
    'source' => 'Domain module manifests + local schemas',
    'report_scope' => 'STATIC_DEFINITION_ONLY',
    'runtime_handlers_checked' => false,
    'tenant_permissions_checked' => false,
    'business_outcomes_checked' => false,
    'counts' => $counts,
    'capabilities' => $entries,
];
echo json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE
    | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR), "\n";
if (in_array('--strict', $argv, true) && ($counts['SCHEMA_INVALID'] ?? 0) > 0) {
    exit(1);
}
