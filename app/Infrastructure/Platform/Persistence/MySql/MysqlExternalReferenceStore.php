<?php
declare(strict_types=1);

namespace Infrastructure\Platform\Persistence\MySql;

use Infrastructure\Platform\Persistence\ExternalReferenceStoreInterface;
use PDO;

final readonly class MysqlExternalReferenceStore implements ExternalReferenceStoreInterface
{
    public function __construct(private PDO $connection) {}

    public function find(string $organizationId, string $provider, string $entityType, string $reference): ?string
    {
        $statement = $this->connection->prepare(
            'SELECT external_id FROM cos_external_references WHERE organization_id = :organizationId '
            . 'AND provider = :provider AND entity_type = :entityType AND cos_reference = :reference LIMIT 1'
        );
        $statement->execute(compact('organizationId', 'provider', 'entityType', 'reference'));
        $value = $statement->fetchColumn();
        return $value === false ? null : (string) $value;
    }

    public function put(string $organizationId, string $provider, string $entityType, string $externalId, string $reference): void
    {
        $this->connection->prepare(
            'INSERT INTO cos_external_references '
            . '(organization_id, provider, entity_type, external_id, cos_reference, last_synced_at) '
            . 'VALUES (:organizationId, :provider, :entityType, :externalId, :reference, NOW(6))'
        )->execute(compact('organizationId', 'provider', 'entityType', 'externalId', 'reference'));
    }
}
