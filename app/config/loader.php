<?php

use Phalcon\Autoload\Loader;

$loader = new Loader();

/**
 * Register Namespaces
 */
$loader->setNamespaces([
    'Terra\Models'          => APP_PATH . '/common/models/',
    'Common\Models'         => APP_PATH . '/common/models/',
    'Terra'                 => APP_PATH . '/common/library/',
    'Common\Services'       => APP_PATH . '/common/services/',
    'Modules\Games'         => APP_PATH . '/modules/games/',
    'Modules\Games\Template' => APP_PATH . '/modules/games/Template/',
    'Modules\TgAdmin'       => APP_PATH . '/modules/TgAdmin/',
    'Modules\Economy'       => APP_PATH . '/modules/economy/',
    'Modules\Frontend'       => APP_PATH . '/modules/frontend/',
    'Modules\Users'         => APP_PATH . '/modules/Users/',
    'Common\UI'             => APP_PATH . '/common/UI/',
]);

/**
 * Register module classes
 */
$loader->setClasses([
    'Modules\Frontend\Module' => APP_PATH . '/modules/frontend/Module.php',
    'Modules\Cli\Module'      => APP_PATH . '/modules/cli/Module.php',
    'Modules\TgAdmin\Module'  => APP_PATH . '/modules/TgAdmin/Module.php',
    'Modules\Games\Module'  => APP_PATH . '/modules/Games/Module.php',
    'Modules\Economy\Module'  => APP_PATH . '/modules/economy/Module.php',
    'Modules\Users\Module'  => APP_PATH . '/modules/Users/Module.php'
]);

$loader->register();
