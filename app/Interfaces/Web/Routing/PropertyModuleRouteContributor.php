<?php
declare(strict_types=1);

namespace Interfaces\Web\Routing;

use Phalcon\Mvc\RouterInterface;

final readonly class PropertyModuleRouteContributor implements ModuleRouteContributorInterface
{
    public function register(RouterInterface $router): void
    {
        PropertyRuntimeRoutes::register($router);
    }
}
