<?php
declare(strict_types=1);

namespace Interfaces\Web\Routing;

use Phalcon\Mvc\RouterInterface;

/**
 * Property business API ownership moved to Symfony /api/v1/properties*.
 * The legacy Web host contributes only public HTML Property routes.
 */
final readonly class PropertyModuleRouteContributor implements ModuleRouteContributorInterface
{
    public function register(RouterInterface $router): void
    {
        PublicPropertyRoutes::register($router);
    }
}
