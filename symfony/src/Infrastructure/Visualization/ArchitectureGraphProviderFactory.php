<?php
declare(strict_types=1);

namespace App\Infrastructure\Visualization;

use Infrastructure\Visualization\Architecture\ArchitectureGraphProvider;
use Infrastructure\Visualization\Architecture\CrossDomainArchitectureGraphProvider;
use Infrastructure\Visualization\Architecture\FallbackArchitectureGraphProvider;
use Kernel\Module\DomainModuleRegistry;
use Kernel\Module\ModuleCatalog;
use Psr\Container\ContainerInterface;
use Throwable;

final readonly class ArchitectureGraphProviderFactory
{
    public function __construct(
        private ModuleCatalog $catalog,
        private ContainerInterface $runtimeModules,
    ) {
    }

    public function create(): FallbackArchitectureGraphProvider
    {
        $staticBase = new ArchitectureGraphProvider($this->catalog, new DomainModuleRegistry([]));
        $staticGraph = new FallbackArchitectureGraphProvider(
            new CrossDomainArchitectureGraphProvider($staticBase, $this->catalog),
            $staticBase,
        );

        try {
            $runtimeModules = $this->runtimeModules->get('registry');
            if (!$runtimeModules instanceof DomainModuleRegistry) {
                throw new \RuntimeException('Architecture runtime module registry is invalid.');
            }

            $runtimeBase = new ArchitectureGraphProvider($this->catalog, $runtimeModules);
            $runtimeGraph = new CrossDomainArchitectureGraphProvider($runtimeBase, $this->catalog);

            return new FallbackArchitectureGraphProvider($runtimeGraph, $staticGraph);
        } catch (Throwable $exception) {
            error_log(sprintf(
                '[COS Visualization] Runtime module registry unavailable for Architecture Graph: %s: %s',
                $exception::class,
                $exception->getMessage(),
            ));

            return $staticGraph;
        }
    }
}
