<?php
declare(strict_types=1);

namespace Infrastructure\Integration\Crm;

use Domains\Sales\Application\Contract\CrmIngressResolverInterface;
use InvalidArgumentException;
use PDO;

final readonly class MysqlCrmIngressResolver implements CrmIngressResolverInterface
{
    public function __construct(private PDO $connection)
    {
    }

    public function resolve(int $integrationId): array
    {
        if ($integrationId <= 0) {
            throw new InvalidArgumentException('Invalid CRM integration endpoint.');
        }

        $statement = $this->connection->prepare(
            'SELECT organization_id,provider
             FROM cos_integrations
             WHERE id=:id AND capability="CRM" AND status="ACTIVE"
             LIMIT 1'
        );
        $statement->execute(['id' => $integrationId]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);

        if (!is_array($row)) {
            throw new InvalidArgumentException('Invalid CRM integration endpoint.');
        }

        $organizationId = trim((string) ($row['organization_id'] ?? ''));
        $provider = strtolower(trim((string) ($row['provider'] ?? '')));

        if (!preg_match('/^[a-z0-9][a-z0-9_-]{0,39}$/', $organizationId)
            || !preg_match('/^[a-z0-9][a-z0-9_-]{0,79}$/', $provider)
        ) {
            throw new InvalidArgumentException('Invalid CRM integration endpoint.');
        }

        return [
            'organization_id' => $organizationId,
            'provider' => $provider,
        ];
    }
}
