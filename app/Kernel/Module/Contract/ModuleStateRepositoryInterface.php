<?php
declare(strict_types=1);

namespace Kernel\Module\Contract;

interface ModuleStateRepositoryInterface
{
    public function enabledOverride(string $organizationId, string $moduleId): ?bool;

    public function setEnabled(string $organizationId, string $moduleId, bool $enabled): void;
}
