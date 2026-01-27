<?php

namespace Modules\TgAdmin;

//use Phalcon\Di\DiInterface;
use Modules\Economy\Listeners\UserEventsListener;
use Modules\TgAdmin\Commands\UserCommands\MenuCommand;
use Modules\TgAdmin\Listeners\WalletEventsListener;
use Phalcon\Mvc\ModuleDefinitionInterface;
use Phalcon\Autoload\Loader;

class Module implements ModuleDefinitionInterface
{
    public function registerAutoloaders(?\Phalcon\Di\DiInterface $di = null):void {
        $loader = new Loader();
        $loader->setNamespaces([
            'Modules\TgAdmin\Controllers' => __DIR__ . '/controllers/',
            'Modules\TgAdmin\Models' => __DIR__ . '/models/',
            'Modules\TgAdmin\Services'    => __DIR__ . '/services/',
        ]);
        $loader->register();
    }



    public function registerServices(\Phalcon\Di\DiInterface $di):void
    {
        // Налаштовуємо dispatcher для цього модуля
        $di->setShared('dispatcher', function() {
            $dispatcher = new \Phalcon\Mvc\Dispatcher();
            $dispatcher->setDefaultNamespace('Modules\TgAdmin\Controllers');
            return $dispatcher;
        });


        // Сервіс для Telegram API
        $di->setShared('telegramService', function() {
            $config = $this->getConfig();
            return new Services\TelegramService(
                $config->telegram->botToken,
                $config->telegram->botName
            );
        });

        $eventService = $di->getShared('eventService');
//        $eventService->attach('wallet:coinAdded', new WalletEventsListener());

    }
}
