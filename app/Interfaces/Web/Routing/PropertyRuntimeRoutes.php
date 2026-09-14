<?php
declare(strict_types=1);

namespace Interfaces\Web\Routing;

use Phalcon\Mvc\RouterInterface;

final class PropertyRuntimeRoutes
{
    public static function register(RouterInterface $router): void
    {
        $api = static fn (string $action): array => [
            'namespace' => 'Interfaces\\Api\\Controller',
            'module' => 'frontend',
            'controller' => 'property_runtime',
            'action' => $action,
        ];

        // Canonical registry runtime is intentionally separate from legacy
        // public catalog compatibility endpoints.
        $router->addGet('/api/v1/property-registry', $api('index'));
        $router->addGet('/api/v1/property-registry/health', $api('health'));
    }
}
