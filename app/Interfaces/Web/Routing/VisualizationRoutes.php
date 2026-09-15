<?php
declare(strict_types=1);

namespace Interfaces\Web\Routing;

use Phalcon\Mvc\RouterInterface;

final class VisualizationRoutes
{
    public static function register(RouterInterface $router): void
    {
        $router->addGet('/cos/architecture', [
            'namespace' => 'Interfaces\\Web\\Visualization\\Controller',
            'module' => 'frontend',
            'controller' => 'architecture_explorer',
            'action' => 'index',
        ]);

        $router->addGet('/cos/architecture/graph', [
            'namespace' => 'Interfaces\\Web\\Visualization\\Controller',
            'module' => 'frontend',
            'controller' => 'architecture_explorer',
            'action' => 'graph',
        ]);
    }
}
