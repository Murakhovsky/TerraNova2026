<?php

$router = $di->getRouter();
$modules = $application->getModules();

foreach ($modules as $name => $module) {
//    if (class_exists($module['className']) && !$module['default']) {
    if (class_exists($module['className'])) {
        /** @var \Phalcon\Mvc\ModuleDefinitionInterface $moduleInstance */
        $moduleInstance = new $module['className'];
        $moduleInstance->registerAutoloaders($di);
        $moduleInstance->registerServices($di);
    }
}
