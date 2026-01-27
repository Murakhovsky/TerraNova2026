<?php

namespace Modules\Games;

use Phalcon\Di\DiInterface;
use Phalcon\Mvc\ModuleDefinitionInterface;
use Phalcon\Autoload\Loader;

class Module implements ModuleDefinitionInterface
{
    public function registerAutoloaders(?DiInterface $di = null):void {
        $loader = new Loader();
        $loader->setNamespaces([
            'Modules\Games\Controllers' => __DIR__ . '/Controllers/',
//            'Modules\Games\Models' => __DIR__ . '/models/',
//            'Modules\Games\Services'    => __DIR__ . '/services/',
        ]);
        $loader->register();
    }

    public function registerServices(DiInterface $di)
    {
        $di->setShared('dispatcher', function() {
            $dispatcher = new \Phalcon\Mvc\Dispatcher();
            $dispatcher->setDefaultNamespace('Modules\Games\Controllers');
            return $dispatcher;
        });

    }
}
