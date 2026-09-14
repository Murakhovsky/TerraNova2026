<?php
declare(strict_types=1);

namespace Interfaces\Web\Routing;

use Phalcon\Mvc\RouterInterface;

final class SalesTeamRoutes
{
    public static function register(RouterInterface $router): void
    {
        $api = static fn (string $action): array => ['namespace'=>'Interfaces\\Api\\Controller','module'=>'frontend','controller'=>'sales_admin_team','action'=>$action];
        $web = static fn (string $action): array => ['namespace'=>'Interfaces\\Web\\Controller','module'=>'frontend','controller'=>'sales_admin_team','action'=>$action];

        $router->addGet('/sales/admin/teams', $web('teams'));
        $router->addGet('/api/sales/admin/teams/catalog', $api('catalog'));
        $router->addGet('/api/sales/admin/users', $api('users'));
        $router->addGet('/api/sales/admin/teams', $api('teams'));
        $router->addPost('/api/sales/admin/teams', $api('create'));
        $router->addGet('/api/sales/admin/teams/{id:[A-Za-z0-9_-]{8,64}}', $api('team'));
        $router->addPost('/api/sales/admin/teams/{id:[A-Za-z0-9_-]{8,64}}', $api('update'));
        $router->addPost('/api/sales/admin/teams/{id:[A-Za-z0-9_-]{8,64}}/members/{userId:[0-9]+}', $api('member'));
        $router->addPost('/api/sales/admin/users/{userId:[0-9]+}/capabilities', $api('capabilities'));
        $router->addGet('/api/sales/admin/authority/revisions/{id:[A-Za-z0-9_:.+-]{1,191}}', $api('revisions'));
    }
}
