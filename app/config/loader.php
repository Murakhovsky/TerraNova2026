<?php

use Phalcon\Autoload\Loader;

$loader = new Loader();

/**
 * Register Namespaces
 */
$loader->setNamespaces([
    'Kernel'            => APP_PATH . '/Kernel/',
    'Domains'           => APP_PATH . '/Domains/',
    'Infrastructure'    => APP_PATH . '/Infrastructure/',
    'Interfaces'        => APP_PATH . '/Interfaces/',
]);

$loader->register();
