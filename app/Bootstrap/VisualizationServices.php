<?php
declare(strict_types=1);

use Infrastructure\Visualization\Architecture\ArchitectureGraphProvider;
use Infrastructure\Visualization\Architecture\ArchitectureProjectionRegistry;
use Infrastructure\Visualization\Architecture\CrossDomainArchitectureGraphProvider;
use Kernel\Visualization\Graph\GraphProviderInterface;

// KernelServices registers the base provider first. Visualization composition deliberately
// replaces the public service with a contract-aware decorator while preserving the same
// renderer-neutral GraphProviderInterface boundary for every consumer.
$di->setShared(
    'cosArchitectureGraphProvider',
    fn (): GraphProviderInterface => new CrossDomainArchitectureGraphProvider(
        new ArchitectureGraphProvider(
            $this->getShared('cosModuleCatalog'),
            $this->getShared('cosDomainRegistry'),
        ),
        $this->getShared('cosModuleCatalog'),
    ),
);

$di->setShared(
    'cosArchitectureProjectionRegistry',
    static fn (): ArchitectureProjectionRegistry => ArchitectureProjectionRegistry::defaults(),
);
