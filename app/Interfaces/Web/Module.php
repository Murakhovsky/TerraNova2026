<?php
declare(strict_types=1);

namespace Interfaces\Web;

use Bootstrap\WebApplicationServices;
use Interfaces\Web\Routing\FrontendRoutes;
use Interfaces\Web\Routing\SalesRoutes;
use Interfaces\Web\Routing\SalesTeamRoutes;
use Interfaces\Web\Routing\SalesIntegrationRoutes;
use Interfaces\Web\Page\PublicPageService;
use Phalcon\Di\DiInterface;
use Phalcon\Mvc\ModuleDefinitionInterface;
use Phalcon\Mvc\View;
use Phalcon\Mvc\View\Engine\Php as PhpEngine;

class Module implements ModuleDefinitionInterface
{
    public function registerAutoloaders(?DiInterface $di = null): void
    {
        // Canonical namespaces are loaded through Composer. Legacy aliases are
        // registered by the application compatibility loader during deprecation.
    }

    public function registerServices(DiInterface $di): void
    {
        $router = $di->getShared('router');
        FrontendRoutes::register($router, array_keys((new PublicPageService())->pages()));
        SalesRoutes::register($router);
        SalesTeamRoutes::register($router);
        SalesIntegrationRoutes::register($router);
        WebApplicationServices::register($di);

        $di->set('view', function () {
            $view = new View();
            $view->setDI($this);
            $view->setViewsDir(APP_PATH . '/Interfaces/Web/View/');
            $view->registerEngines(['.phtml' => PhpEngine::class]);

            return $view;
        });
    }
}