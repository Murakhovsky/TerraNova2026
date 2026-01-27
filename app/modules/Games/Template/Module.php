<?php

namespace Modules\Games\Plugins\Template;

use Phalcon\Di\DiInterface;
use Phalcon\Mvc\ModuleDefinitionInterface;

class Module implements ModuleDefinitionInterface
{
    public function registerAutoloaders(DiInterface $di = null) {}

    public function registerServices(DiInterface $di)
    {
        // Реєструємо свій сервіс під унікальним іменем
        $di->setShared('templateService', function() {
            return new Services\TemplateService();
        });
    }
}
