<?php
declare(strict_types=1);

namespace Interfaces\Web\Routing;

use Phalcon\Mvc\RouterInterface;

final class PlatformRoutes
{
    public static function register(RouterInterface $router): void
    {
        $router->addGet('/api/platform/modules', self::target('index'));
        $router->addPost('/api/platform/modules/{id:[a-z][a-z0-9_]*}/install', self::target('install'));
        $router->addPost('/api/platform/modules/{id:[a-z][a-z0-9_]*}/upgrade', self::target('upgrade'));
        $router->addPost('/api/platform/modules/{id:[a-z][a-z0-9_]*}/enable', self::target('enable'));
        $router->addPost('/api/platform/modules/{id:[a-z][a-z0-9_]*}/disable', self::target('disable'));
        $router->addPost('/api/platform/modules/{id:[a-z][a-z0-9_]*}/uninstall', self::target('uninstall'));
    }

    /** @return array<string, string> */
    private static function target(string $action): array
    {
        return [
            'namespace' => 'Interfaces\\Api\\Controller',
            'module' => 'frontend',
            'controller' => 'platform_module',
            'action' => $action,
        ];
    }
}
