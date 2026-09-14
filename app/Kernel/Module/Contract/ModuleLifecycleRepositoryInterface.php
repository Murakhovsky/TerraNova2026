<?php
declare(strict_types=1);

namespace Kernel\Module\Contract;

use Kernel\Module\ModuleInstallation;
use Kernel\Module\ModuleManifest;

interface ModuleLifecycleRepositoryInterface
{
    public function find(string $organizationId, string $moduleId): ?ModuleInstallation;

    /** @return list<ModuleInstallation> */
    public function forOrganization(string $organizationId): array;

    public function record(string $organizationId, ModuleManifest $manifest, string $status): void;
}
