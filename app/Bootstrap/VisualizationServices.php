<?php
declare(strict_types=1);

use Infrastructure\Visualization\Architecture\ArchitectureGraphHealthAnalyzer;
use Infrastructure\Visualization\Architecture\ArchitectureGraphProvider;
use Infrastructure\Visualization\Architecture\ArchitectureProjectionRegistry;
use Infrastructure\Visualization\Architecture\CrossDomainArchitectureGraphProvider;
use Infrastructure\Visualization\Architecture\FallbackArchitectureGraphProvider;
use Kernel\Module\DomainModuleRegistry;
use Kernel\Visualization\Graph\GraphHealthAnalyzerInterface;
use Kernel\Visualization\Graph\GraphProviderInterface;

// Visualization must remain observable even when one operational runtime module cannot
// be composed. Full runtime topology is preferred; the fallback keeps the structural
// ModuleCatalog graph available without weakening the strict runtime registry itself.
$di->setShared('cosArchitectureGraphProvider', function (): GraphProviderInterface {
    $catalog = $this->getShared('cosModuleCatalog');

    $staticBase = new ArchitectureGraphProvider(
        $catalog,
        new DomainModuleRegistry([]),
    );
    $staticGraph = new FallbackArchitectureGraphProvider(
        new CrossDomainArchitectureGraphProvider($staticBase, $catalog),
        $staticBase,
    );

    try {
        $runtimeRegistry = $this->getShared('cosDomainRegistry');
    } catch (\Throwable $exception) {
        error_log(sprintf(
            '[COS Visualization] Runtime module registry unavailable for Architecture Graph: %s: %s',
            $exception::class,
            $exception->getMessage(),
        ));
        return $staticGraph;
    }

    $runtimeGraph = new CrossDomainArchitectureGraphProvider(
        new ArchitectureGraphProvider($catalog, $runtimeRegistry),
        $catalog,
    );

    return new FallbackArchitectureGraphProvider($runtimeGraph, $staticGraph);
});

// Phalcon DI binds service factories to the container instance. This factory must not
// be static, otherwise Closure::call() cannot bind it and the resolved service becomes invalid.
$di->setShared(
    'cosArchitectureProjectionRegistry',
    fn (): ArchitectureProjectionRegistry => ArchitectureProjectionRegistry::defaults(),
);

$di->setShared(
    'cosArchitectureGraphHealthAnalyzer',
    fn (): GraphHealthAnalyzerInterface => new ArchitectureGraphHealthAnalyzer(),
);
