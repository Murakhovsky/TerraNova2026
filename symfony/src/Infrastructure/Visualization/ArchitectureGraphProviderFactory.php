<?php
declare(strict_types=1);

namespace App\Infrastructure\Visualization;

use Infrastructure\Visualization\Architecture\ArchitectureGraphProvider;
use Infrastructure\Visualization\Architecture\CrossDomainArchitectureGraphProvider;
use Infrastructure\Visualization\Architecture\FallbackArchitectureGraphProvider;
use Kernel\Module\DomainModuleRegistry;
use Kernel\Module\ModuleCatalog;
use Kernel\Visualization\Graph\GraphProviderInterface;

final readonly class ArchitectureGraphProviderFactory
{
    public function __construct(
        private ModuleCatalog $catalog,
        private DomainModuleRegistry $runtimeModules,
    ) {
    }

    public function create(): GraphProviderInterface
    {
        $staticBase = new ArchitectureGraphProvider($this->catalog, new DomainModuleRegistry([]));
        $staticGraph = new FallbackArchitectureGraphProvider(
            new CrossDomainArchitectureGraphProvider($staticBase, $this->catalog),
            $staticBase,
        );

        $runtimeBase = new ArchitectureGraphProvider($this->catalog, $this->runtimeModules);
        $runtimeGraph = new CrossDomainArchitectureGraphProvider($runtimeBase, $this->catalog);

        return new FallbackArchitectureGraphProvider($runtimeGraph, $staticGraph);
    }
}
