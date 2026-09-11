<?php
declare(strict_types=1);

namespace Interfaces\Web\Routing;

use Phalcon\Mvc\RouterInterface;

final readonly class SalesModuleRouteContributor implements ModuleRouteContributorInterface
{
    public function __construct(private ModuleRouteAccessGuard $access)
    {
    }

    public function register(RouterInterface $router): void
    {
        $existing = [];
        foreach ($router->getRoutes() as $route) {
            $existing[spl_object_id($route)] = true;
        }

        SalesRoutes::register($router);
        SalesTeamRoutes::register($router);
        SalesIntegrationRoutes::register($router);
        SalesAdministrationRoutes::register($router);

        foreach ($router->getRoutes() as $route) {
            if (isset($existing[spl_object_id($route)])) {
                continue;
            }

            $route->beforeMatch(fn (...$unused): bool => $this->access->allows('sales'));
        }
    }
}
