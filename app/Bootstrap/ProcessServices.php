<?php
declare(strict_types=1);

use Infrastructure\Process\JsonProcessRegistry;
use Kernel\Process\ProcessRegistryInterface;

$di->setShared(
    'cosProcessRegistry',
    static fn (): ProcessRegistryInterface => new JsonProcessRegistry(BASE_PATH . '/resources/processes'),
);
