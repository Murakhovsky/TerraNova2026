<?php
declare(strict_types=1);

namespace Interfaces\Web\Routing;

use InvalidArgumentException;
use Phalcon\Mvc\RouterInterface;

final readonly class ModuleRouteRegistrar
{
    public function __construct(private ModuleRouteAccessGuard $access)
    {
    }

    public function register(
        string $moduleId,
        ModuleRouteContributorInterface $contributor,
        RouterInterface $router,
    ): void {
        if (!preg_match('/^[a-z][a-z0-9_]*$/', $moduleId)) {
            throw new InvalidArgumentException(sprintf('Invalid module id: %s.', $moduleId));
        }

        $existing = [];
        foreach ($router->getRoutes() as $route) {
            $existing[spl_object_id($route)] = true;
        }

        $contributor->register($router);

        foreach ($router->getRoutes() as $route) {
            if (isset($existing[spl_object_id($route)])) {
                continue;
            }

            $route->beforeMatch(fn (...$unused): bool => $this->access->allows($moduleId));
        }
    }
}
