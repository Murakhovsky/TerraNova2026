<?php
declare(strict_types=1);

namespace Interfaces\Web\Routing;

use Phalcon\Mvc\RouterInterface;

final readonly class SalesModuleRouteContributor implements ModuleRouteContributorInterface
{
    public function register(RouterInterface $router): void
    {
        SalesRoutes::register($router);
        SalesTeamRoutes::register($router);
        SalesIntegrationRoutes::register($router);
        SalesAdministrationRoutes::register($router);
    }
}
