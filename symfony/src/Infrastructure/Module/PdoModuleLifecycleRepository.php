<?php
declare(strict_types=1);

namespace App\Infrastructure\Module;

use Kernel\Module\Contract\ModuleLifecycleRepositoryInterface;
use Kernel\Module\ModuleInstallation;
use Kernel\Module\ModuleManifest;
use PDO;

final readonly class PdoModuleLifecycleRepository implements ModuleLifecycleRepositoryInterface
{
    public function __construct(private PDO $connection)
    {
    }

    public function find(string $organizationId, string $moduleId): ?ModuleInstallation
    {
        $statement = $this->connection->prepare(
            'SELECT organization_id,module_id,status,installed_version,schema_version '
            . 'FROM cos_module_installations WHERE organization_id=:org AND module_id=:module LIMIT 1'
        );
        $statement->execute(['org' => $organizationId, 'module' => $moduleId]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);

        return is_array($row) ? $this->hydrate($row) : null;
    }

    public function forOrganization(string $organizationId): array
    {
        $statement = $this->connection->prepare(
            'SELECT organization_id,module_id,status,installed_version,schema_version '
            . 'FROM cos_module_installations WHERE organization_id=:org ORDER BY module_id'
        );
        $statement->execute(['org' => $organizationId]);

        return array_map(
            fn (array $row): ModuleInstallation => $this->hydrate($row),
            $statement->fetchAll(PDO::FETCH_ASSOC) ?: [],
        );
    }

    public function record(string $organizationId, ModuleManifest $manifest, string $status): void
    {
        $statement = $this->connection->prepare(
            'INSERT INTO cos_module_installations(organization_id,module_id,status,installed_version,schema_version) '
            . 'VALUES(:org,:module,:status,:version,:schema_version) '
            . 'ON DUPLICATE KEY UPDATE status=VALUES(status),installed_version=VALUES(installed_version),'
            . 'schema_version=VALUES(schema_version),updated_at=CURRENT_TIMESTAMP'
        );
        $statement->execute([
            'org' => $organizationId,
            'module' => $manifest->id,
            'status' => $status,
            'version' => $manifest->version,
            'schema_version' => $manifest->schemaVersion,
        ]);
    }

    /** @param array<string,mixed> $row */
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
