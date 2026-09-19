<?php
declare(strict_types=1);

namespace Interfaces\Web\Routing;

use Phalcon\Mvc\RouterInterface;

final class SalesTeamRoutes
{
    public static function register(RouterInterface $router): void
    {
        $web = static fn (string $action): array => ['namespace'=>'Interfaces\\Web\\Controller','module'=>'frontend','controller'=>'sales_admin_team','action'=>$action];
        $router->addGet('/sales/admin/teams', $web('teams'));
    }
}
