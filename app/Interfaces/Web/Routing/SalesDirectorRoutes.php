<?php
declare(strict_types=1);

namespace Interfaces\Web\Routing;

use Phalcon\Mvc\RouterInterface;

final class SalesDirectorRoutes
{
    public static function register(RouterInterface $router): void
    {
        $router->addGet('/api/sales/director/overview', [
            'namespace' => 'Interfaces\\Api\\Controller',
            'module' => 'frontend',
            'controller' => 'sales_director',
            'action' => 'overview',
        ]);
    }
}
