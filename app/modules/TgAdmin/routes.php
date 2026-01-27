<?php
use Phalcon\Mvc\Router\Group;

$group = new Group([
    'module'    => 'TgAdmin',
    'namespace' => 'Modules\TgAdmin\Controllers',
]);

// всі вхідні від Telegram будуть POST на /tgAdmin/webhook
$group->setPrefix('/TgAdmin');
$group->addPost('/webhook', [
    'controller' => 'webhook',
    'action'     => 'index',
]);

return $group;
