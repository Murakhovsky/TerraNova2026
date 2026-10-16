#!/usr/bin/env php
<?php
declare(strict_types=1);

use Kernel\Module\CanonicalCapabilityCatalog;
use Kernel\Module\ModuleCatalog;
use Kernel\Module\ModuleDiscovery;

$root = dirname(__DIR__);
require $root . '/vendor/autoload.php';
$catalog = new CanonicalCapabilityCatalog(new ModuleCatalog(
    (new ModuleDiscovery($root . '/app/Domains'))->discover(),
));
$report = $catalog->driftReport();
$counts = [];
foreach ($report as $item) $counts[$item['status']] = ($counts[$item['status']] ?? 0) + 1;
echo json_encode([
    'schema_version' => '1.0.0',
    'source' => 'module manifests',
    'note' => 'Runtime bindings are not inspected; INVALID means unverified binding, not proven defect.',
    'counts' => $counts, 'capabilities' => $report,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR), "\n";
