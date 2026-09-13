<?php
declare(strict_types=1);

namespace Kernel\Module\Contract;

interface BulkModuleStateRepositoryInterface extends ModuleStateRepositoryInterface
{
    /** @return array<string, bool> Indexed by module id. */
    public function enabledOverrides(string $organizationId): array;
}
