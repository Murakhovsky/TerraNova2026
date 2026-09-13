<?php
declare(strict_types=1);

namespace Infrastructure\Module;

use Infrastructure\Platform\Persistence\Pdo\PdoConnection;
use Kernel\Module\Contract\BulkModuleStateRepositoryInterface;

final readonly class MysqlModuleStateRepository implements BulkModuleStateRepositoryInterface
{
    public function __construct(private PdoConnection $database)
    {
    }

    public function enabledOverride(string $organizationId, string $moduleId): ?bool
    {
        $row = $this->database->fetchOne(
            'SELECT enabled FROM cos_organization_module WHERE organization_id = :organization_id AND module_id = :module_id LIMIT 1',
            ['organization_id' => $organizationId, 'module_id' => $moduleId],
        );

        return $row === null ? null : (bool) $row['enabled'];
    }

    /** @return array<string, bool> */
    public function enabledOverrides(string $organizationId): array
    {
        $rows = $this->database->fetchAll(
            'SELECT module_id, enabled FROM cos_organization_module WHERE organization_id = :organization_id ORDER BY module_id',
            ['organization_id' => $organizationId],
        );

        $overrides = [];
        foreach ($rows as $row) {
            $overrides[(string) $row['module_id']] = (bool) $row['enabled'];
        }

        return $overrides;
    }

    public function setEnabled(string $organizationId, string $moduleId, bool $enabled): void
    {
        $statement = $this->database->connection()->prepare(<<<'SQL'
INSERT INTO cos_organization_module (organization_id, module_id, enabled)
VALUES (:organization_id, :module_id, :enabled)
ON DUPLICATE KEY UPDATE enabled = VALUES(enabled), updated_at = CURRENT_TIMESTAMP
SQL);
        $statement->execute([
            'organization_id' => $organizationId,
            'module_id' => $moduleId,
            'enabled' => $enabled ? 1 : 0,
        ]);
    }
}
