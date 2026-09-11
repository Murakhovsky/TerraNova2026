<?php
declare(strict_types=1);

namespace Kernel\Module;

final readonly class ModuleExtensionContribution
{
    public function __construct(
        public string $moduleId,
        public string $extensionPoint,
        public string $serviceId,
    ) {
    }
}
