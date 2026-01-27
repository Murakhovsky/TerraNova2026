<?php
namespace Modules\Users;

use Modules\TgAdmin\Renderer\Messages;
use Modules\Users\Listeners\UserEventsListener;
use Modules\Users\Services\UsersService;
use Phalcon\Mvc\ModuleDefinitionInterface;
use Phalcon\Autoload\Loader;
class Module implements ModuleDefinitionInterface
{
    public function registerAutoloaders(?\Phalcon\Di\DiInterface $di = null):void {

        $loader = new Loader();
        $loader->setNamespaces([
            'Modules\Users\Controllers' => __DIR__ . '/controllers/',
            'Modules\Users\Models' => __DIR__ . '/models/',
            'Modules\Users\Models\Person' => __DIR__ . '/models/Person',
            'Modules\Users\Services'    => __DIR__ . '/services/',
        ]);
        $loader->register();
    }

    public function registerServices(\Phalcon\Di\DiInterface $di): void
    {
        $di->setShared('userService', function () {
            return new UsersService();
        });

        $eventService = $di->getShared('eventService');

        $eventService->attach('user:newProfileSaved', new UserEventsListener());
        $eventService->attach('user:registered', new UserEventsListener());
        $eventService->attach('user:profileCompleted', new UserEventsListener());
        $eventService->attach('user:login', new UserEventsListener());
        $eventService->attach('user:loginDaily', new UserEventsListener());
        $eventService->attach('user:logout', new UserEventsListener());

        $eventService->attach('user:levelUp', new UserEventsListener());
        $eventService->attach('user:xpAdded', new UserEventsListener());
        $eventService->attach('user:statusChanged', new UserEventsListener());

        $eventService->attach('user:referralJoined', new UserEventsListener());
        $eventService->attach('user:referralActivated', new UserEventsListener());
    }
}
