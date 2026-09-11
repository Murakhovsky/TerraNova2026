<?php
declare(strict_types=1);

namespace Kernel\Module;

use Kernel\Configuration\Service\ConfigurationProvisioner;
use Kernel\Database\MigrationRunnerInterface;
use RuntimeException;
use Throwable;

final readonly class ModuleTenantProvisioner
{
    public function __construct(
        private ModuleCatalog $catalog,
        private MigrationRunnerInterface $migrations,
        private ConfigurationProvisioner $configuration,
    ) {
    }

    /**
     * Verify that deployment-level schema migrations are already applied, then provision
     * tenant-scoped defaults for the requested module and its dependency tree.
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

        if ($definition->contributions->runtimeModuleService !== null) {
            $provisioned = $this->configuration->provisionDomain($organizationId, $moduleId, $actorId);
            $result['domains'] += $provisioned['domains'];
            $result['rules'] += $provisioned['rules'];
            $result['policies'] += $provisioned['policies'];
            $result['manifest_hashes'] = array_merge(
                $result['manifest_hashes'],
                $provisioned['manifest_hashes'],
            );
        }

        $visited[$moduleId] = true;
        $result['modules'][] = $moduleId;
    }
}
