<?php
declare(strict_types=1);

namespace Modules\Frontend;

use Phalcon\Di\DiInterface;
use Phalcon\Autoload\Loader;
use Phalcon\Mvc\View;
use Phalcon\Mvc\View\Engine\Php as PhpEngine;
use Phalcon\Mvc\ModuleDefinitionInterface;
use Modules\Frontend\Services\CatalogService;
use Modules\Frontend\Services\ClientCaseService;
use Modules\Frontend\Services\InboundRequestService;
use Modules\Frontend\Services\PropertyMediaService;
use Modules\Frontend\Services\PropertyModerationService;
use Modules\Frontend\Services\PropertySubmissionService;

class Module implements ModuleDefinitionInterface
{
    /**
     * Registers an autoloader related to the module
     *
     * @param DiInterface $di
     */
    public function registerAutoloaders(?DiInterface $di = null):void
    {
        $loader = new Loader();

        $loader->setNamespaces([
            'Modules\Frontend\Controllers' => __DIR__ . '/controllers/',
            'Modules\Frontend\Models' => __DIR__ . '/models/',
        ]);

        $loader->register();
    }

    /**
     * Registers services related to the module
     *
     * @param DiInterface $di
     */
    public function registerServices(DiInterface $di)
    {
        $router = $di->getShared('router');
        $router->setDefaultNamespace('Modules\Frontend\Controllers');
        $router->setDefaultController('index');
        $router->setDefaultAction('index');

        $router->add('/submit-property', [
            'namespace' => 'Modules\Frontend\Controllers',
            'module' => 'frontend',
            'controller' => 'property',
            'action' => 'submit',
        ]);

        $router->add('/property/create', [
            'namespace' => 'Modules\Frontend\Controllers',
            'module' => 'frontend',
            'controller' => 'property',
            'action' => 'submit',
        ]);

        $router->add('/property/:action/:params', [
            'namespace' => 'Modules\Frontend\Controllers',
            'module' => 'frontend',
            'controller' => 'property',
            'action' => 1,
            'params' => 2,
        ]);

        $router->add('/property/:action', [
            'namespace' => 'Modules\Frontend\Controllers',
            'module' => 'frontend',
            'controller' => 'property',
            'action' => 1,
        ]);

        $router->add('/property', [
            'namespace' => 'Modules\Frontend\Controllers',
            'module' => 'frontend',
            'controller' => 'property',
            'action' => 'catalog',
        ]);

        $router->add('/client-case/:action/:params', [
            'namespace' => 'Modules\Frontend\Controllers',
            'module' => 'frontend',
            'controller' => 'client_case',
            'action' => 1,
            'params' => 2,
        ]);

        $router->add('/client-case/:action', [
            'namespace' => 'Modules\Frontend\Controllers',
            'module' => 'frontend',
            'controller' => 'client_case',
            'action' => 1,
        ]);

        $router->add('/client-case', [
            'namespace' => 'Modules\Frontend\Controllers',
            'module' => 'frontend',
            'controller' => 'client_case',
            'action' => 'index',
        ]);

        $di->setShared('frontendClientCaseService', function () {
            return new ClientCaseService($this->getShared('databaseService'));
        });

        $di->setShared('frontendCatalogService', function () {
            return new CatalogService($this->getShared('databaseService'));
        });

        $di->setShared('frontendInboundRequestService', function () {
            return new InboundRequestService($this->getShared('frontendClientCaseService'));
        });

        $di->setShared('frontendPropertySubmissionService', function () {
            return new PropertySubmissionService($this->getShared('mediaStorageService'));
        });

        $di->setShared('frontendPropertyModerationService', function () {
            return new PropertyModerationService(
                $this->getShared('databaseService'),
                $this->getShared('mediaStorageService')
            );
        });

        $di->setShared('frontendPropertyMediaService', function () {
            return new PropertyMediaService(
                $this->getShared('databaseService'),
                $this->getShared('mediaStorageService')
            );
        });

        /**
         * Setting up the view component
         */
        $di->set('view', function () {
            $view = new View();
            $view->setDI($this);
            $view->setViewsDir(__DIR__ . '/views/');

            $view->registerEngines([
//                '.volt'  => 'voltShared',
                '.phtml' => PhpEngine::class
            ]);

            return $view;
        });
    }
}
