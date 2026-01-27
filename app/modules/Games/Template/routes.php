<?php
use Phalcon\Mvc\Router\Group;

$group = new Group([
    'namespace' => 'Modules\Games\Plugins\Template\Controllers',
    'prefix'    => '/api/v1/games/template'
]);

$group->addGet('/play',    ['controller'=>'template','action'=>'play']);
$group->addPost('/submit', ['controller'=>'template','action'=>'submit']);

return $group;
