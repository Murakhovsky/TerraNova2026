<?php
declare(strict_types=1);

namespace Kernel\Configuration\Service;

use Kernel\Configuration\Contract\ConfigurationStoreInterface;
use Kernel\Module\DomainModuleInterface;
use Kernel\Module\DomainModuleRegistry;
use RuntimeException;

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
        $result = $this->emptyResult();
        foreach ($this->registry->modules() as $module) {
            $this->mergeResult($result, $this->provisionModule($module, $organizationId, $actorId));
        }

        return $result;
    }

    /** @return array{domains: int, rules: int, policies: int, manifest_hashes: array<string, string>} */
    public function provisionDomain(string $organizationId, string $domainName, string $actorId): array
    {
        foreach ($this->registry->modules() as $module) {
            if ($module->name() === $domainName) {
                return $this->provisionModule($module, $organizationId, $actorId);
            }
        }

        throw new RuntimeException(sprintf('Domain module is not registered: %s.', $domainName));
    }

    /** @return array{domains: int, rules: int, policies: int, manifest_hashes: array<string, string>} */
    private function provisionModule(DomainModuleInterface $module, string $organizationId, string $actorId): array
    {
        $rules = $module->rules($organizationId);
        $policies = $module->policies($organizationId);
        $this->validator->validate($module, $organizationId, $rules, $policies);
        $hash = hash('sha256', serialize([$rules, $policies]));
        $this->store->provision($organizationId, $module->name(), $rules, $policies, $hash, $actorId);

        return [
            'domains' => 1,
            'rules' => count($rules),
            'policies' => count($policies),
            'manifest_hashes' => [$module->name() => $hash],
        ];
    }

    /** @return array{domains: int, rules: int, policies: int, manifest_hashes: array<string, string>} */
    private function emptyResult(): array
    {
        return ['domains' => 0, 'rules' => 0, 'policies' => 0, 'manifest_hashes' => []];
    }

    /**
     * @param array{domains: int, rules: int, policies: int, manifest_hashes: array<string, string>} $target
     * @param array{domains: int, rules: int, policies: int, manifest_hashes: array<string, string>} $source
     */
    private function mergeResult(array &$target, array $source): void
    {
        $target['domains'] += $source['domains'];
        $target['rules'] += $source['rules'];
        $target['policies'] += $source['policies'];
        $target['manifest_hashes'] = array_merge($target['manifest_hashes'], $source['manifest_hashes']);
    }
}
