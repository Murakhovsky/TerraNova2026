<?php
declare(strict_types=1);

use Infrastructure\Visualization\Architecture\ArchitectureProjectionRegistry;

$di->setShared(
    'cosArchitectureProjectionRegistry',
    static fn (): ArchitectureProjectionRegistry => ArchitectureProjectionRegistry::defaults(),
);
