<?php
declare(strict_types=1);

namespace Interfaces\Web\Routing;

use Phalcon\Mvc\RouterInterface;

/**
 * Application-owned routes that are not business-domain capabilities.
 *
 * Domain/module routes remain owned by their route contributors. This class
 * closes the old Phalcon default-route dependency for the root, auth, Portal
 * and Workspace administration entry points and owns the global 404 target.
 */
final class CoreWebRoutes
{
    public static function register(RouterInterface $router): void
    {
        $web = static fn(string $controller, string $action): array => self::target($controller, $action);

        // Public root accepts POST because the homepage owns the inbound request form.
        $router->add('/', $web('index', 'index'));

        // Authentication boundary. Login/register intentionally accept GET + POST.

        // Portal.
        $router->add('/cabinet', $web('cabinet', 'index'));
        $router->add('/cabinet/submission/{id:[0-9]+}', $web('cabinet', 'submission') + ['id' => 1]);

        // Workspace core / Administration.
        $router->add('/admin', $web('admin', 'index'));
        $router->add('/admin/users', $web('admin', 'users'));
        $router->add('/admin/analytics', $web('admin', 'analytics'));
        $router->addPost('/admin/createUser', $web('admin', 'createUser'));
        $router->addPost('/admin/updateUser/{id:[0-9]+}', $web('admin', 'updateUser') + ['id' => 1]);

        $router->notFound($web('error', 'notFound'));
    }

    /** @return array{namespace:string,module:string,controller:string,action:string} */
    private static function target(string $controller, string $action): array
    {
        return [
            'namespace' => 'Interfaces\\Web\\Controller',
            'module' => 'frontend',
            'controller' => $controller,
            'action' => $action,
        ];
    }
}
