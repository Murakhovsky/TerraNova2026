<?php
declare(strict_types=1);

namespace Modules\TgAdmin;

use Phalcon\Autoload\Loader;
use Phalcon\Di\DiInterface;
use Phalcon\Mvc\Dispatcher;
use Phalcon\Mvc\ModuleDefinitionInterface;

class Module implements ModuleDefinitionInterface
{
    public function registerAutoloaders(?DiInterface $di = null): void
    {
        $loader = new Loader();
        $loader->setNamespaces([
            'Modules\TgAdmin\Controllers' => __DIR__ . '/controllers/',
            'Modules\TgAdmin\Models' => __DIR__ . '/models/',
            'Modules\TgAdmin\Services' => __DIR__ . '/services/',
        ]);
        $loader->register();
    }

    public function registerServices(DiInterface $di): void
    {
        $di->getShared('router')->addPost('/TgAdmin/webhook', [
            'namespace' => 'Modules\TgAdmin\Controllers',
            'module' => 'TgAdmin',
            'controller' => 'webhook',
            'action' => 'index',
        ]);

        $di->setShared('dispatcher', function () {
            $dispatcher = new Dispatcher();
            $dispatcher->setDefaultNamespace('Modules\TgAdmin\Controllers');
            return $dispatcher;
        });

        $di->setShared('telegramBot', function () {
            $config = $this->getConfig();
            return new TelegramBotEB($config->telegram, $config->telegram->database, $this);
        });
    }
}
