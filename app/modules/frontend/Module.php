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
use Modules\Frontend\Services\CosConsoleService;
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

        $router->addPost('/cos/action/{id:[a-f0-9]{32}}/execute', [
            'namespace' => 'Modules\\Frontend\\Controllers',
            'module' => 'frontend',
            'controller' => 'cos',
            'action' => 'execute',
        ]);

        $router->addPost('/cos/approval/{id:[a-f0-9]{32}}/approve', [
            'namespace' => 'Modules\\Frontend\\Controllers',
            'module' => 'frontend',
            'controller' => 'cos',
            'action' => 'approve',
        ]);

        $router->addPost('/cos/approval/{id:[a-f0-9]{32}}/reject', [
            'namespace' => 'Modules\\Frontend\\Controllers',
            'module' => 'frontend',
            'controller' => 'cos',
            'action' => 'reject',
        ]);

        $router->add('/cos', [
            'namespace' => 'Modules\\Frontend\\Controllers',
            'module' => 'frontend',
            'controller' => 'company_os',
            'action' => 'index',
            'lang' => 'en',
        ]);

        $router->add('/cos/{lang:[a-z]{2}}', [
            'namespace' => 'Modules\\Frontend\\Controllers',
            'module' => 'frontend',
            'controller' => 'company_os',
            'action' => 'index',
        ]);

        $router->add('/cos/{lang:[a-z]{2}}/domains/{slug:[a-z0-9-]+}', [
            'namespace' => 'Modules\\Frontend\\Controllers',
            'module' => 'frontend',
            'controller' => 'company_os',
            'action' => 'domain',
        ]);

        $router->add('/cos/control-center', [
            'namespace' => 'Modules\\Frontend\\Controllers',
            'module' => 'frontend',
            'controller' => 'cos',
            'action' => 'index',
        ]);

        $router->addPost('/api/approvals/{id:[a-f0-9]{32}}/approve', [
            'namespace' => 'Modules\\Frontend\\Controllers',
            'module' => 'frontend',
            'controller' => 'approval',
            'action' => 'approve',
        ]);

        $router->addPost('/api/approvals/{id:[a-f0-9]{32}}/reject', [
            'namespace' => 'Modules\\Frontend\\Controllers',
            'module' => 'frontend',
            'controller' => 'approval',
            'action' => 'reject',
        ]);

        $di->setShared('frontendClientCaseService', function () {
            return new ClientCaseService(
                $this->getShared('databaseService'),
                $this->getShared('eventBus'),
                $this->getShared('cosTransactionManager'),
                (string) $this->getConfig()->cos->organizationId,
            );
        });

        $di->setShared('frontendCatalogService', function () {
            return new CatalogService($this->getShared('databaseService'));
        });

        $di->setShared('frontendInboundRequestService', function () {
            return new InboundRequestService(
                $this->getShared('frontendClientCaseService'),
                $this->getShared('databaseService'),
                $this->getShared('eventBus'),
                $this->getShared('cosTransactionManager'),
                (string) $this->getConfig()->cos->organizationId,
            );
        });

        $di->setShared('frontendCosConsoleService', function () {
            return new CosConsoleService(
                $this->getShared('databaseService'),
                (string) $this->getConfig()->cos->organizationId,
            );
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
