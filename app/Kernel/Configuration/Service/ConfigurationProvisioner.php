<?php
declare(strict_types=1);

namespace Kernel\Configuration\Service;

use Kernel\Configuration\Contract\ConfigurationStoreInterface;
use Kernel\Module\DomainModuleRegistry;

final readonly class ConfigurationProvisioner
{
    public function __construct(
        private DomainModuleRegistry $registry,
        private ConfigurationValidator $validator,
        private ConfigurationStoreInterface $store,
    ) {
    }

    /** @return array{domains: int, rules: int, policies: int, manifest_hashes: array<string, string>} */
    public function provision(string $organizationId, string $actorId): array
    {
        $result = ['domains' => 0, 'rules' => 0, 'policies' => 0, 'manifest_hashes' => []];
        foreach ($this->registry->modules() as $module) {
            $rules = $module->rules($organizationId);
            $policies = $module->policies($organizationId);
            $this->validator->validate($module, $organizationId, $rules, $policies);
            $hash = hash('sha256', serialize([$rules, $policies]));
            $this->store->provision($organizationId, $module->name(), $rules, $policies, $hash, $actorId);
            $result['domains']++;
            $result['rules'] += count($rules);
            $result['policies'] += count($policies);
            $result['manifest_hashes'][$module->name()] = $hash;
        }
        return $result;
    }
}
