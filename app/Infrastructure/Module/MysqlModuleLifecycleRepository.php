<?php
declare(strict_types=1);

namespace Infrastructure\Module;

use Infrastructure\Platform\Persistence\Pdo\PdoConnection;
use Kernel\Module\Contract\ModuleLifecycleRepositoryInterface;
use Kernel\Module\ModuleInstallation;
use Kernel\Module\ModuleManifest;

final readonly class MysqlModuleLifecycleRepository implements ModuleLifecycleRepositoryInterface
{
    public function __construct(private PdoConnection $database)
    {
    }

    public function find(string $organizationId, string $moduleId): ?ModuleInstallation
    {
        $row = $this->database->fetchOne(
            'SELECT organization_id, module_id, status, installed_version, schema_version
             FROM cos_module_installations
             WHERE organization_id = :organization_id AND module_id = :module_id
             LIMIT 1',
            ['organization_id' => $organizationId, 'module_id' => $moduleId],
        );

        return $row === null ? null : $this->hydrate($row);
    }

    /** @return list<ModuleInstallation> */
    public function forOrganization(string $organizationId): array
    {
        $rows = $this->database->fetchAll(
            'SELECT organization_id, module_id, status, installed_version, schema_version
             FROM cos_module_installations
             WHERE organization_id = :organization_id
             ORDER BY module_id',
            ['organization_id' => $organizationId],
        );

        return array_map(fn (array $row): ModuleInstallation => $this->hydrate($row), $rows);
    }

    public function record(string $organizationId, ModuleManifest $manifest, string $status): void
    {
        $statement = $this->database->connection()->prepare(<<<'SQL'
INSERT INTO cos_module_installations
    (organization_id, module_id, status, installed_version, schema_version)
VALUES
    (:organization_id, :module_id, :status, :installed_version, :schema_version)
ON DUPLICATE KEY UPDATE
    status = VALUES(status),
    installed_version = VALUES(installed_version),
    schema_version = VALUES(schema_version),
    updated_at = CURRENT_TIMESTAMP
SQL);
        $statement->execute([
            'organization_id' => $organizationId,
            'module_id' => $manifest->id,
            'status' => $status,
            'installed_version' => $manifest->version,
            'schema_version' => $manifest->schemaVersion,
        ]);
    }

    /** @param array<string, mixed> $row */
    private function hydrate(array $row): ModuleInstallation
    {
        return new ModuleInstallation(
            (string) $row['organization_id'],
            (string) $row['module_id'],
            (string) $row['status'],
            (string) $row['installed_version'],
            (string) $row['schema_version'],
        );
    }
}
