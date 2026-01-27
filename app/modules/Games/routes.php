<?php

use Phalcon\Mvc\Router\Group;

$router = new Group();
$router->setPrefix('/api/v1/games');

foreach (glob(__DIR__ . '/*/routes.php') as $routesPath) {
    $group = require $routesPath;
    $router->mount($group);
}

return $router;
