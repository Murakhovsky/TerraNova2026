<?php
declare(strict_types=1);

namespace Interfaces\Web\Routing;

use Phalcon\Mvc\RouterInterface;

final class SalesRoutes
{
    public static function register(RouterInterface $router): void
    {
        $target = static fn (string $action): array => [
            'namespace' => 'Interfaces\\Api\\Controller',
            'module' => 'frontend',
            'controller' => 'sales',
            'action' => $action,
        ];

        $router->addPost('/api/sales/deals/{id:[0-9]+}/quick', $target('quickUpdate'));
        $router->addPost('/api/sales/deals/{id:[0-9]+}/activities', $target('activity'));
        $router->addPost('/api/sales/deals/{id:[0-9]+}/followups', $target('followup'));
        $router->addPost('/api/sales/deals/{id:[0-9]+}/meetings', $target('meeting'));
        $router->addPost('/api/sales/deals/{id:[0-9]+}/owner', $target('owner'));
    }
}
