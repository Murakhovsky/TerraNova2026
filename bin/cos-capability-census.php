#!/usr/bin/env php
<?php
declare(strict_types=1);

use Kernel\Module\CapabilityCatalogCoverageAudit;
use Kernel\Module\CanonicalCapabilityCatalog;
use Kernel\Module\ModuleCatalog;
use Kernel\Module\ModuleDiscovery;

$root=dirname(__DIR__);
require $root.'/vendor/autoload.php';

// Single definition authority, compiled into deterministic inventory.
// Never infer worker, tenant, or native business readiness from manifests.
$catalog=new CanonicalCapabilityCatalog(new ModuleCatalog(
    (new ModuleDiscovery($root.'/app/Domains'))->discover()
));
$report=(new CapabilityCatalogCoverageAudit($catalog,$root))->report();
echo json_encode($report,JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE
    | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),"\n";
if (in_array('--strict',$argv,true)
    && ($report['counts']['invalid_contract'] > 0)) {
    exit(1);
}
if (in_array('--require-classified',$argv,true)
    && ($report['counts']['unclassified_legacy'] > 0
        || $report['counts']['invalid_contract'] > 0)) {
    exit(2);
}
if (in_array('--require-executables-ready',$argv,true)
    && ($report['counts']['contract_candidates'] > 0
        || $report['counts']['unclassified_legacy'] > 0
        || $report['counts']['invalid_contract'] > 0)) {
    exit(3);
}
// Backward-compatible strongest possible gate. Informational feature-surface
// markers are intentionally not typed as fake executable contracts.
if (in_array('--require-all-typed',$argv,true)
    && ($report['counts']['declarative_only'] > 0
        || $report['counts']['invalid_contract'] > 0)) {
    exit(4);
}
