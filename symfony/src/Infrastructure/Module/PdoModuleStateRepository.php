<?php
declare(strict_types=1);

namespace App\Infrastructure\Module;

use Kernel\Module\Contract\BulkModuleStateRepositoryInterface;
use PDO;

final readonly class PdoModuleStateRepository implements BulkModuleStateRepositoryInterface
{
    public function __construct(private PDO $connection)
    {
    }

    public function enabledOverride(string $organizationId, string $moduleId): ?bool
    {
        $statement = $this->connection->prepare(
            'SELECT enabled FROM cos_organization_modules WHERE organization_id=:org AND module_id=:module LIMIT 1'
        );
        $statement->execute(['org' => $organizationId, 'module' => $moduleId]);
        $value = $statement->fetchColumn();

        return $value === false ? null : (bool) $value;
    }

    public function enabledOverrides(string $organizationId): array
    {
        $statement = $this->connection->prepare(
            'SELECT module_id,enabled FROM cos_organization_modules WHERE organization_id=:org'
        );
        $statement->execute(['org' => $organizationId]);

        $result = [];
        foreach ($statement->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
            $result[(string) $row['module_id']] = (bool) $row['enabled'];
        }

        return $result;
    }

    public function setEnabled(string $organizationId, string $moduleId, bool $enabled): void
    {
        $statement = $this->connection->prepare(
            'INSERT INTO cos_organization_modules(organization_id,module_id,enabled) VALUES(:org,:module,:enabled) '
            . 'ON DUPLICATE KEY UPDATE enabled=VALUES(enabled),updated_at=CURRENT_TIMESTAMP'
        );
        $statement->execute([
            'org' => $organizationId,
            'module' => $moduleId,
            'enabled' => $enabled ? 1 : 0,
        ]);
    }
}
