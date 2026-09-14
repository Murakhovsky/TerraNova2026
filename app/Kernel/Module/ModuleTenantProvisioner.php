<?php
declare(strict_types=1);

namespace Kernel\Module;

use Kernel\Database\MigrationRunnerInterface;
use Kernel\Module\Contract\ModuleConfigurationProvisionerInterface;
use RuntimeException;
use Throwable;

final readonly class ModuleTenantProvisioner
{
    /**
     * @param list<array{module_id: string, service: ModuleConfigurationProvisionerInterface}> $configurationProvisioners
     */
    public function __construct(
        private ModuleCatalog $catalog,
        private MigrationRunnerInterface $migrations,
        private array $configurationProvisioners = [],
    ) {
        foreach ($this->configurationProvisioners as $contribution) {
            if (!is_string($contribution['module_id'] ?? null)
                || !($contribution['service'] ?? null) instanceof ModuleConfigurationProvisionerInterface
            ) {
                throw new RuntimeException('Invalid module configuration provisioner contribution.');
            }
        }
    }

    /**
     * Verify that deployment-level schema migrations are already applied, then provision
     * tenant-scoped defaults explicitly declared by the requested module and dependency tree.
     *
     * @return array{modules: list<string>, domains: int, rules: int, policies: int, manifest_hashes: array<string, string>}
     */
    public function provision(string $organizationId, string $moduleId, string $actorId): array
    {
        $appliedMigrations = $this->appliedMigrationNames();
        $visited = [];
        $result = [
            'modules' => [],
            'domains' => 0,
            'rules' => 0,
            'policies' => 0,
            'manifest_hashes' => [],
        ];

        $this->provisionRecursive($organizationId, $moduleId, $actorId, $appliedMigrations, $visited, $result);

        return $result;
    }

    /** @return array<string, true> */
    private function appliedMigrationNames(): array
    {
        try {
            $rows = $this->migrations->status();
        } catch (Throwable $error) {
            throw new RuntimeException(
                'Cannot verify module schema readiness. Run deployment migrations before tenant provisioning.',
                0,
                $error,
            );
        }

        $applied = [];
        foreach ($rows as $row) {
            if (!is_array($row) || !is_string($row['migration'] ?? null) || $row['migration'] === '') {
                continue;
            }
            $applied[$row['migration']] = true;
        }

        return $applied;
    }

    /**
     * @param array<string, true> $appliedMigrations
     * @param array<string, true> $visited
     * @param array{modules: list<string>, domains: int, rules: int, policies: int, manifest_hashes: array<string, string>} $result
     */
    private function provisionRecursive(
        string $organizationId,
        string $moduleId,
        string $actorId,
        array $appliedMigrations,
        array &$visited,
        array &$result,
    ): void {
        if (isset($visited[$moduleId])) {
            return;
        }

        $definition = $this->catalog->definition($moduleId);
        foreach ($definition->manifest->dependencies as $dependencyId) {
            $this->provisionRecursive(
                $organizationId,
                $dependencyId,
                $actorId,
                $appliedMigrations,
                $visited,
                $result,
            );
        }

        $missing = [];
        foreach ($definition->contributions->migrationFiles as $migrationFile) {
            $migrationName = pathinfo($migrationFile, PATHINFO_FILENAME);
            if (!isset($appliedMigrations[$migrationName])) {
                $missing[] = $migrationName;
            }
        }

        if ($missing !== []) {
            throw new RuntimeException(sprintf(
                'Module %s schema is not ready. Run deployment migrations first. Missing: %s.',
                $moduleId,
                implode(', ', $missing),
            ));
        }

        foreach ($this->configurationProvisioners as $contribution) {
            if ($contribution['module_id'] !== $moduleId) {
                continue;
            }

            $this->mergeProvisioningResult(
                $result,
                $contribution['service']->provision($organizationId, $actorId),
            );
        }

        $visited[$moduleId] = true;
        $result['modules'][] = $moduleId;
    }

    /**
     * @param array{modules: list<string>, domains: int, rules: int, policies: int, manifest_hashes: array<string, string>} $target
     * @param array{domains: int, rules: int, policies: int, manifest_hashes: array<string, string>} $source
     */
    private function mergeProvisioningResult(array &$target, array $source): void
    {
        $target['domains'] += $source['domains'];
        $target['rules'] += $source['rules'];
        $target['policies'] += $source['policies'];
        $target['manifest_hashes'] = array_merge($target['manifest_hashes'], $source['manifest_hashes']);
    }
}
