<?php
declare(strict_types=1);

namespace Interfaces\Telegram;

use Phalcon\Di\DiInterface;
use Phalcon\Mvc\Dispatcher;
use Phalcon\Mvc\ModuleDefinitionInterface;

class Module implements ModuleDefinitionInterface
{
    public function registerAutoloaders(?DiInterface $di = null): void
    {
        // Composer owns all canonical Telegram namespaces.
    }

    public function registerServices(DiInterface $di): void
    {
        $di->getShared('router')->addPost('/TgAdmin/webhook', [
            'namespace' => 'Interfaces\Telegram\Controller',
            'module' => 'TgAdmin',
            'controller' => 'webhook',
            'action' => 'index',
        ]);

        $di->setShared('dispatcher', function () {
            $dispatcher = new Dispatcher();
            $dispatcher->setDefaultNamespace('Interfaces\Telegram\Controller');
            return $dispatcher;
        });

        $di->setShared('telegramBot', function () {
            $config = $this->getConfig();
            return new TelegramBot($config->telegram, $config->telegram->database, $this);
        });
    }
}

