<?php
declare(strict_types=1);

namespace Kernel\Module\Contract;

interface ModuleConfigurationProvisionerInterface
{
    /**
     * Provision tenant-scoped defaults owned by one module.
     *
     * @return array{domains: int, rules: int, policies: int, manifest_hashes: array<string, string>}
     */
    public function provision(string $organizationId, string $actorId): array;
}
