<?php
declare(strict_types=1);

namespace Infrastructure\Module;

use Infrastructure\Platform\Persistence\Pdo\PdoConnection;
use JsonException;
use Kernel\Module\Contract\ModuleStateRepositoryInterface;

final readonly class MysqlModuleStateRepository implements ModuleStateRepositoryInterface
{
    public function __construct(private PdoConnection $database)
    {
    }

    public function enabledOverride(string $organizationId, string $moduleId): ?bool
    {
        $row = $this->database->fetchOne(
            'SELECT enabled FROM cos_organization_modules WHERE organization_id = :organization_id AND module_id = :module_id LIMIT 1',
            ['organization_id' => $organizationId, 'module_id' => $moduleId],
        );

        return $row === null ? null : ((int) $row['enabled'] === 1);
    }

    /** @throws JsonException */
    public function setEnabled(string $organizationId, string $moduleId, bool $enabled, array $configuration = []): void
    {
        $statement = $this->database->connection()->prepare(<<<'SQL'
INSERT INTO cos_organization_modules (organization_id, module_id, enabled, configuration_json)
VALUES (:organization_id, :module_id, :enabled, :configuration_json)
ON DUPLICATE KEY UPDATE
    enabled = VALUES(enabled),
    configuration_json = VALUES(configuration_json),
    updated_at = CURRENT_TIMESTAMP
SQL);
        $statement->execute([
            'organization_id' => $organizationId,
            'module_id' => $moduleId,
            'enabled' => $enabled ? 1 : 0,
            'configuration_json' => $configuration === [] ? null : json_encode($configuration, JSON_THROW_ON_ERROR),
        ]);
    }
}
