<?php
declare(strict_types=1);

namespace Interfaces\Web;

use Bootstrap\WebApplicationServices;
use Interfaces\Web\Page\PublicPageService;
use Interfaces\Web\Routing\FrontendRoutes;
use Interfaces\Web\Routing\ModuleRouteContributorInterface;
use Interfaces\Web\Routing\PlatformRoutes;
use Phalcon\Di\DiInterface;
use Phalcon\Mvc\ModuleDefinitionInterface;
use Phalcon\Mvc\View;
use Phalcon\Mvc\View\Engine\Php as PhpEngine;
use RuntimeException;

class Module implements ModuleDefinitionInterface
{
    public function registerAutoloaders(?DiInterface $di = null): void
    {
        // Canonical namespaces are loaded through Composer. Legacy aliases are
        // registered by the application compatibility loader during deprecation.
    }

    public function registerServices(DiInterface $di): void
    {
        WebApplicationServices::register($di);

        $router = $di->getShared('router');
        FrontendRoutes::register($router, array_keys((new PublicPageService())->pages()));
        PlatformRoutes::register($router);

        foreach ((array) $di->getShared('cosModuleApiRouteContributors') as $contribution) {
            $moduleId = $contribution['module_id'] ?? null;
            $service = $contribution['service'] ?? null;
            if (!is_string($moduleId) || !$service instanceof ModuleRouteContributorInterface) {
                throw new RuntimeException('Invalid module route contributor.');
            }

            $service->register($router);
        }

        $di->set('view', function () {
            $view = new View();
            $view->setDI($this);
            $view->setViewsDir(APP_PATH . '/Interfaces/Web/View/');
            $view->registerEngines(['.phtml' => PhpEngine::class]);
            return $view;
        });
    }
}
