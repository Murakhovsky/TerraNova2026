<?php
declare(strict_types=1);

namespace Modules\Frontend;

use Phalcon\Di\DiInterface;
use Phalcon\Autoload\Loader;
use Phalcon\Mvc\View;
use Phalcon\Mvc\View\Engine\Php as PhpEngine;
use Phalcon\Mvc\ModuleDefinitionInterface;
use Modules\Frontend\Services\AdminDashboardService;
use Modules\Frontend\Services\AnalyticsService;
use Modules\Frontend\Services\CatalogService;
use Modules\Frontend\Services\ClientCaseService;
use Modules\Frontend\Services\ContentService;
use Modules\Frontend\Services\InboundRequestService;
use Modules\Frontend\Services\N8nWebhookService;
use Modules\Frontend\Services\PropertyMediaService;
use Modules\Frontend\Services\PropertyModerationService;
use Modules\Frontend\Services\PropertyPresentationService;
use Modules\Frontend\Services\PropertySubmissionService;
use Modules\Frontend\Services\PublicPageService;

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
            'Modules\Frontend\Services' => __DIR__ . '/services/',
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

        $router->add('/sitemap.xml', [
            'namespace' => 'Modules\Frontend\Controllers',
            'module' => 'frontend',
            'controller' => 'seo',
            'action' => 'sitemap',
        ]);

        $router->add('/robots.txt', [
            'namespace' => 'Modules\Frontend\Controllers',
            'module' => 'frontend',
            'controller' => 'seo',
            'action' => 'robots',
        ]);

        $router->add('/analytics/track', [
            'namespace' => 'Modules\Frontend\Controllers',
            'module' => 'frontend',
            'controller' => 'analytics',
            'action' => 'track',
        ]);

        $router->addPost('/webhooks/n8n/content', [
            'namespace' => 'Modules\Frontend\Controllers',
            'module' => 'frontend',
            'controller' => 'n8n_webhook',
            'action' => 'content',
        ]);

        $router->add('/blog', [
            'namespace' => 'Modules\Frontend\Controllers',
            'module' => 'frontend',
            'controller' => 'blog',
            'action' => 'index',
        ]);

        $router->add('/blog/{slug:[a-z0-9-]+}', [
            'namespace' => 'Modules\Frontend\Controllers',
            'module' => 'frontend',
            'controller' => 'blog',
            'action' => 'show',
        ]);

        $router->add('/guide/{slug:[a-z0-9-]+}', [
            'namespace' => 'Modules\Frontend\Controllers',
            'module' => 'frontend',
            'controller' => 'blog',
            'action' => 'landing',
        ]);

        $router->add('/nerukhomist/{location:[a-z0-9-]+}/{type:[a-z0-9-]+}', [
            'namespace' => 'Modules\Frontend\Controllers',
            'module' => 'frontend',
            'controller' => 'property',
            'action' => 'landing',
        ]);

        foreach (array_keys((new PublicPageService())->pages()) as $pageSlug) {
            $router->add('/' . $pageSlug, [
                'namespace' => 'Modules\Frontend\Controllers',
                'module' => 'frontend',
                'controller' => 'page',
                'action' => 'show',
                'slug' => $pageSlug,
            ]);
        }

        $router->add('/api/property/:action/:params', [
            'namespace' => 'Modules\Frontend\Controllers',
            'module' => 'frontend',
            'controller' => 'api',
            'action' => 1,
            'params' => 2,
        ]);

        $router->add('/api/property/:action', [
            'namespace' => 'Modules\Frontend\Controllers',
            'module' => 'frontend',
            'controller' => 'api',
            'action' => 1,
        ]);

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

        $router->add('/cabinet/:action/:params', [
            'namespace' => 'Modules\Frontend\Controllers',
            'module' => 'frontend',
            'controller' => 'cabinet',
            'action' => 1,
            'params' => 2,
        ]);

        $router->add('/cabinet/:action', [
            'namespace' => 'Modules\Frontend\Controllers',
            'module' => 'frontend',
            'controller' => 'cabinet',
            'action' => 1,
        ]);

        $router->add('/cabinet', [
            'namespace' => 'Modules\Frontend\Controllers',
            'module' => 'frontend',
            'controller' => 'cabinet',
            'action' => 'index',
        ]);

        $router->add('/admin/:action/:params', [
            'namespace' => 'Modules\Frontend\Controllers',
            'module' => 'frontend',
            'controller' => 'admin',
            'action' => 1,
            'params' => 2,
        ]);

        $router->add('/admin/:action', [
            'namespace' => 'Modules\Frontend\Controllers',
            'module' => 'frontend',
            'controller' => 'admin',
            'action' => 1,
        ]);

        $router->add('/admin', [
            'namespace' => 'Modules\Frontend\Controllers',
            'module' => 'frontend',
            'controller' => 'admin',
            'action' => 'index',
        ]);

        $router->add('/admin/content', [
            'namespace' => 'Modules\Frontend\Controllers',
            'module' => 'frontend',
            'controller' => 'content',
            'action' => 'manage',
        ]);

        $router->add('/admin/content/edit', [
            'namespace' => 'Modules\Frontend\Controllers',
            'module' => 'frontend',
            'controller' => 'content',
            'action' => 'edit',
        ]);

        $router->add('/admin/content/edit/{id:[0-9]+}', [
            'namespace' => 'Modules\Frontend\Controllers',
            'module' => 'frontend',
            'controller' => 'content',
            'action' => 'edit',
        ]);

        $router->addPost('/admin/content/save/{id:[0-9]+}', [
            'namespace' => 'Modules\Frontend\Controllers',
            'module' => 'frontend',
            'controller' => 'content',
            'action' => 'save',
        ]);

        $router->add('/spatial/manage', [
            'namespace' => 'Modules\Frontend\Controllers', 'module' => 'frontend',
            'controller' => 'spatial', 'action' => 'manage',
        ]);
        $router->add('/spatial/edit', [
            'namespace' => 'Modules\Frontend\Controllers', 'module' => 'frontend',
            'controller' => 'spatial', 'action' => 'edit',
        ]);
        $router->add('/spatial/edit/{id:[0-9]+}', [
            'namespace' => 'Modules\Frontend\Controllers', 'module' => 'frontend',
            'controller' => 'spatial', 'action' => 'edit',
        ]);
        foreach (['save', 'upload', 'external', 'capture', 'hotspot', 'publish'] as $spatialAction) {
            $router->addPost('/spatial/' . $spatialAction . '/{id:[0-9]+}', [
                'namespace' => 'Modules\Frontend\Controllers', 'module' => 'frontend',
                'controller' => 'spatial', 'action' => $spatialAction,
            ]);
        }
        $router->add('/spatial/scene/{slug:[a-z0-9-]+}', [
            'namespace' => 'Modules\Frontend\Controllers', 'module' => 'frontend',
            'controller' => 'spatial', 'action' => 'scene',
        ]);

        $di->setShared('frontendAdminDashboardService', function () {
            return new AdminDashboardService($this->getShared('databaseService'));
        });

        $di->setShared('frontendAnalyticsService', function () {
            return new AnalyticsService($this->getShared('databaseService'));
        });

        $di->setShared('frontendPublicPageService', function () {
            return new PublicPageService();
        });

        $di->setShared('frontendContentService', function () {
            return new ContentService($this->getShared('databaseService'));
        });

        $di->setShared('frontendN8nWebhookService', function () {
            $config = $this->getShared('config')->integrations->n8n;
            return new N8nWebhookService(
                $this->getShared('databaseService'),
                $this->getShared('frontendContentService'),
                (string) $config->inbound_secret,
                (int) $config->max_clock_skew
            );
        });

        $di->setShared('frontendClientCaseService', function () {
            return new ClientCaseService($this->getShared('databaseService'));
        });

        $di->setShared('frontendCatalogService', function () {
            return new CatalogService($this->getShared('databaseService'));
        });

        $di->setShared('frontendInboundRequestService', function () {
            return new InboundRequestService(
                $this->getShared('frontendClientCaseService'),
                $this->getShared('databaseService'),
                $this->getShared('telegramAutomationService')
            );
        });

        $di->setShared('frontendPropertySubmissionService', function () {
            return new PropertySubmissionService(
                $this->getShared('mediaStorageService'),
                $this->getShared('databaseService'),
                $this->getShared('telegramAutomationService')
            );
        });

        $di->setShared('frontendPropertyModerationService', function () {
            return new PropertyModerationService(
                $this->getShared('databaseService'),
                $this->getShared('mediaStorageService'),
                $this->getShared('telegramAutomationService')
            );
        });

        $di->setShared('frontendPropertyMediaService', function () {
            return new PropertyMediaService(
                $this->getShared('databaseService'),
                $this->getShared('mediaStorageService'),
                $this->getShared('telegramAutomationService')
            );
        });

        $di->setShared('frontendPropertyPresentationService', function () {
            return new PropertyPresentationService(
                $this->getShared('frontendCatalogService'),
                $this->getShared('databaseService'),
                $this->getShared('telegramAutomationService')
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
