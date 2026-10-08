#!/usr/bin/env php
<?php
declare(strict_types=1);

use Infrastructure\Visualization\Architecture\ArchitectureGraphProvider;
use Infrastructure\Visualization\Architecture\ArchitectureImpactAnalysisService;
use Infrastructure\Visualization\Architecture\CrossDomainArchitectureGraphProvider;
use Kernel\Module\DomainModuleRegistry;
use Kernel\Module\ModuleCatalog;
use Kernel\Module\ModuleDiscovery;

$root = dirname(__DIR__);
require $root . '/vendor/autoload.php';
$changed = array_slice($argv, 1);
if ($changed === []) {
    fwrite(STDERR, "Usage: php bin/federation-impact.php contract:sha1... domain:sales capability:sales.lead ...\n");
    exit(64);
}
$modules = new ModuleCatalog((new ModuleDiscovery($root . '/app/Domains'))->discover());
$provider = new CrossDomainArchitectureGraphProvider(
    new ArchitectureGraphProvider($modules, new DomainModuleRegistry([])), $modules,
);
$impact = (new ArchitectureImpactAnalysisService($provider))->analyze($changed);
echo json_encode($impact, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR), "\n";
if ($impact['unknown_nodes'] !== []) exit(2);
