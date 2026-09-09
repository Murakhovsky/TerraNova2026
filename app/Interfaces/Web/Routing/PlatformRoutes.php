<?php
declare(strict_types=1);

namespace Interfaces\Web\Routing;

use Phalcon\Mvc\RouterInterface;

final class PlatformRoutes
{
    public static function register(RouterInterface $router): void
    {
        $router->addGet('/api/platform/modules', [
            'namespace' => 'Interfaces\\Api\\Controller',
            'module' => 'frontend',
            'controller' => 'platform_module',
            'action' => 'index',
        ]);
    }
}
