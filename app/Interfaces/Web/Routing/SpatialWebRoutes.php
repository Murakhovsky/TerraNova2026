<?php
declare(strict_types=1);

namespace Interfaces\Web\Routing;

use Phalcon\Mvc\RouterInterface;

/**
 * Explicit Web routes for the temporary legacy Spatial workspace controller.
 *
 * Spatial API routes are canonical on Symfony. These routes preserve only the
 * remaining SSR workspace journeys until the final Phalcon Web retirement.
 */
final class SpatialWebRoutes
{
    public static function register(RouterInterface $router): void
    {
        $router->addGet('/spatial/manage', self::target('manage'));
        $router->addGet('/spatial/edit', self::target('edit'));
        $router->addGet('/spatial/edit/{id:[0-9]+}', self::target('edit') + ['id' => 1]);

        $router->addPost('/spatial/save', self::target('save'));
        $router->addPost('/spatial/save/{id:[0-9]+}', self::target('save') + ['id' => 1]);
        $router->addPost('/spatial/upload/{id:[0-9]+}', self::target('upload') + ['id' => 1]);
        $router->addPost('/spatial/external/{id:[0-9]+}', self::target('external') + ['id' => 1]);
        $router->addPost('/spatial/capture/{id:[0-9]+}', self::target('capture') + ['id' => 1]);
        $router->addPost('/spatial/hotspot/{id:[0-9]+}', self::target('hotspot') + ['id' => 1]);
        $router->addPost('/spatial/publish/{id:[0-9]+}', self::target('publish') + ['id' => 1]);

        $router->addGet('/spatial/scene/{slug:[A-Za-z0-9_-]+}', self::target('scene') + ['slug' => 1]);
    }

    /** @return array{namespace:string,module:string,controller:string,action:string} */
    private static function target(string $action): array
    {
        return [
            'namespace' => 'Interfaces\\Web\\Controller',
            'module' => 'frontend',
            'controller' => 'spatial',
            'action' => $action,
        ];
    }
}
