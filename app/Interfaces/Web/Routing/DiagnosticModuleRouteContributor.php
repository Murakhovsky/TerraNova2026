<?php
declare(strict_types=1);

namespace Interfaces\Web\Routing;

use Phalcon\Mvc\RouterInterface;

final readonly class DiagnosticModuleRouteContributor implements ModuleRouteContributorInterface
{
    public function register(RouterInterface $router): void
    {
        DiagnosticRoutes::register($router);
    }
}
