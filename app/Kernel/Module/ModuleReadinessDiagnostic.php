<?php
declare(strict_types=1);

namespace Kernel\Module;

use Kernel\Database\MigrationRunnerInterface;
use Throwable;

final readonly class ModuleReadinessDiagnostic
{
    public function __construct(
        private ModuleCatalog $catalog,
        private ActiveModuleResolver $modules,
        private MigrationRunnerInterface $migrations,
    ) {
    }

    /** @return array<string, mixed> */
    public function diagnose(string $organizationId): array
    {
        $descriptions = [];
        foreach ($this->modules->describe($organizationId) as $description) {
            $descriptions[(string) $description['id']] = $description;
        }

        $schemaStatusAvailable = true;
        $appliedMigrations = [];
        try {
            foreach ($this->migrations->status() as $row) {
                if (is_array($row) && is_string($row['migration'] ?? null) && $row['migration'] !== '') {
                    $appliedMigrations[$row['migration']] = true;
                }
            }
        } catch (Throwable) {
            // Readiness is diagnostic-only. Do not leak database errors to the control-plane API.
            $schemaStatusAvailable = false;
        }

        $result = [];
        foreach ($this->catalog->all() as $manifest) {
            $definition = $this->catalog->definition($manifest->id);
            $description = $descriptions[$manifest->id] ?? [];
            $missingMigrations = [];
            foreach ($definition->contributions->migrationFiles as $migrationFile) {
                $migrationName = pathinfo($migrationFile, PATHINFO_FILENAME);
                if ($schemaStatusAvailable && !isset($appliedMigrations[$migrationName])) {
                    $missingMigrations[] = $migrationName;
                }
            }

            $unavailableDependencies = [];
            foreach ($manifest->dependencies as $dependencyId) {
                if (!$this->modules->isEnabled($organizationId, $dependencyId)) {
                    $unavailableDependencies[] = $dependencyId;
                }
            }

            $installed = (bool) ($description['installed'] ?? false);
            $current = (bool) ($description['current'] ?? false);
            $configuredEnabled = (bool) ($description['configured_enabled'] ?? false);
            $hasMigrationRequirements = $definition->contributions->migrationFiles !== [];

            $status = match (true) {
                !$installed => 'UNINSTALLED',
                !$current => 'UPGRADE_REQUIRED',
                !$schemaStatusAvailable && $hasMigrationRequirements => 'SCHEMA_STATUS_UNAVAILABLE',
                $missingMigrations !== [] => 'SCHEMA_NOT_READY',
                $unavailableDependencies !== [] => 'DEPENDENCY_NOT_READY',
                !$configuredEnabled => 'DISABLED',
                default => 'READY',
            };

            $result[] = [
                'id' => $manifest->id,
                'status' => $status,
                'active' => (bool) ($description['active'] ?? false),
                'installed' => $installed,
                'configured_enabled' => $configuredEnabled,
                'current' => $current,
                'installed_version' => $description['installed_version'] ?? null,
                'deployed_version' => $manifest->version,
                'installed_schema_version' => $description['installed_schema_version'] ?? null,
                'deployed_schema_version' => $manifest->schemaVersion,
                'missing_migrations' => $missingMigrations,
                'unavailable_dependencies' => $unavailableDependencies,
            ];
        }

        return [
            'organization_id' => $organizationId,
            'kernel_version' => KernelVersion::VERSION,
            'schema_status' => $schemaStatusAvailable ? 'AVAILABLE' : 'UNAVAILABLE',
            'modules' => $result,
        ];
    }
}
