<?php
declare(strict_types=1);

namespace Modules\Economy;

use Modules\Economy\Services\EconomyService;
use Modules\Economy\Listeners\UserEventsListener;
use Modules\TgAdmin\Listeners\WalletEventsListener;
use Phalcon\Di\DiInterface;
use Phalcon\Autoload\Loader;
use Phalcon\Mvc\Dispatcher;
use Phalcon\Mvc\ModuleDefinitionInterface;

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
            'Modules\Economy\Controllers' => __DIR__ . '/controllers/',
            'Modules\Economy\Models' => __DIR__ . '/models/',
            'Modules\Economy\Services' => __DIR__ . '/services/',
        ]);

        $loader->register();
    }

    /**
     * Registers services related to the module
     *
     * @param DiInterface $di
     */
    public function registerServices(DiInterface $di):void
    {
        $di->setShared('walletService', function () {
            return new EconomyService();
        });

        $di->setShared('dispatcher', function() {
            $dispatcher = new Dispatcher();
            $dispatcher->setDefaultNamespace('Modules\Economy\Controllers');

            return $dispatcher;
        });

        $eventService = $di->getShared('eventService');
        $eventService->attach('wallet:coinAdded', new WalletEventsListener());
    }
}
