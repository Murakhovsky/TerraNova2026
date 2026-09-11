<?php
declare(strict_types=1);

namespace Interfaces\Web\Routing;

use Phalcon\Mvc\RouterInterface;

final class SalesAdministrationRoutes
{
    public static function register(RouterInterface $router): void
    {
        $router->addGet('/sales/admin/health', [
            'namespace'=>'Interfaces\\Web\\Controller','module'=>'frontend','controller'=>'sales_admin_health','action'=>'index',
        ]);
        $router->addGet('/api/sales/admin/health', [
            'namespace'=>'Interfaces\\Api\\Controller','module'=>'frontend','controller'=>'sales_admin_health','action'=>'index',
        ]);
    }
}
