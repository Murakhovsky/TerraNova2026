<?php
declare(strict_types=1);

namespace Interfaces\Web\Routing;

use Phalcon\Mvc\RouterInterface;

final class SalesRoutes
{
    public static function register(RouterInterface $router): void
    {
        $web = static fn (string $action): array => ['namespace'=>'Interfaces\\Web\\Controller','module'=>'frontend','controller'=>'sales','action'=>$action];
        $adminWeb = static fn (string $action): array => ['namespace'=>'Interfaces\\Web\\Controller','module'=>'frontend','controller'=>'sales_admin','action'=>$action];
        $adminAgentWeb = static fn (string $action): array => ['namespace'=>'Interfaces\\Web\\Controller','module'=>'frontend','controller'=>'sales_admin_agent','action'=>$action];
        $adminPolicyWeb = static fn (string $action): array => ['namespace'=>'Interfaces\\Web\\Controller','module'=>'frontend','controller'=>'sales_admin_policy','action'=>$action];

        // Phalcon remains the server-rendered HTML shell during the final frontend migration.
        // All interactive Sales data and mutations are owned by Symfony /api/v1/sales/*.
        $router->addGet('/sales', $web('today'));

        $router->addGet('/sales/admin/pipelines', $adminWeb('pipelines'));
        $router->addGet('/sales/admin/pipelines/{id:[A-Za-z0-9_-]{8,64}}', $adminWeb('pipeline'));

        $router->addGet('/sales/admin/rules', $adminWeb('rules'));
        $router->addGet('/sales/admin/rules/{id:[A-Za-z0-9_.-]{8,64}}', $adminWeb('rule'));

        $router->addGet('/sales/admin/agents', $adminAgentWeb('agents'));
        $router->addGet('/sales/admin/agents/{name:[A-Za-z0-9_.-]{3,160}}', $adminAgentWeb('agent'));

        $router->addGet('/sales/admin/actions', $adminPolicyWeb('actions'));
    }
}
