<?php

declare(strict_types=1);

namespace Interfaces\Web\Routing;

use Phalcon\Mvc\RouterInterface;

final class PublicPropertyRoutes
{
    public static function register(RouterInterface $router): void
    {
        $target = static fn(string $action): array => [
            'namespace' => 'Interfaces\\Web\\Controller',
            'module' => 'frontend',
            'controller' => 'public_property',
            'action' => $action,
        ];

        $router->add('/property', $target('catalog'));
        $router->add('/property/catalog', $target('catalog'));
        $router->add('/property/map', $target('map'));
        $router->add('/property/show/{slug:[a-z0-9-]+}', $target('show') + ['slug' => 1]);
        $router->add('/property/presentation/{slug:[a-z0-9-]+}', $target('presentation') + ['slug' => 1]);
        $router->add('/property/submit', $target('submit'));
        $router->add('/property/create', $target('submit'));
        $router->add('/submit-property', $target('submit'));
    }
}
