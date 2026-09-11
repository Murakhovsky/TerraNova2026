<?php
declare(strict_types=1);

namespace Interfaces\Web\Routing;

use Phalcon\Mvc\RouterInterface;

final class SalesIntegrationRoutes
{
    public static function register(RouterInterface $router): void
    {
        $api = static fn (string $action): array => [
            'namespace' => 'Interfaces\\Api\\Controller',
            'module' => 'frontend',
            'controller' => 'sales_admin_integration',
            'action' => $action,
        ];
        $web = static fn (string $action): array => [
            'namespace' => 'Interfaces\\Web\\Controller',
            'module' => 'frontend',
            'controller' => 'sales_admin_integration',
            'action' => $action,
        ];

        $router->addGet('/sales/admin/integrations', $web('integrations'));
        $router->addGet('/api/sales/admin/integrations/catalog', $api('catalog'));
        $router->addGet('/api/sales/admin/integrations', $api('integrations'));
        $router->addGet('/api/sales/admin/integrations/routing-options', $api('routingOptions'));
        $router->addPost('/api/sales/admin/integrations', $api('create'));
        $router->addGet('/api/sales/admin/integrations/{id:[0-9]+}', $api('integration'));
        $router->addPost('/api/sales/admin/integrations/{id:[0-9]+}', $api('update'));
        $router->addPost('/api/sales/admin/integrations/{id:[0-9]+}/test', $api('test'));
        $router->addPost('/api/sales/admin/integrations/{id:[0-9]+}/routes', $api('route'));
        $router->addGet('/api/sales/admin/integrations/{id:[0-9]+}/revisions', $api('revisions'));
    }
}
