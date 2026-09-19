<?php
declare(strict_types=1);

namespace Interfaces\Web\Routing;

use Phalcon\Mvc\RouterInterface;

final class SalesIntegrationRoutes
{
    public static function register(RouterInterface $router): void
    {
        $web = static fn (string $action): array => [
            'namespace' => 'Interfaces\\Web\\Controller',
            'module' => 'frontend',
            'controller' => 'sales_admin_integration',
            'action' => $action,
        ];

        $router->addGet('/sales/admin/integrations', $web('integrations'));
    }
}
