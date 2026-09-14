<?php
declare(strict_types=1);

namespace Infrastructure\Integration\Crm;

use Domains\Sales\Application\Contract\OrganizationCrmResolverInterface;
use PDO;
use RuntimeException;

final readonly class MysqlOrganizationCrmResolver implements OrganizationCrmResolverInterface
{
    public function __construct(private PDO $connection)
    {
    }

    public function providerFor(string $organizationId): string
    {
        $statement = $this->connection->prepare(
            "SELECT provider FROM cos_integrations WHERE organization_id = :organization_id "
            . "AND capability = 'CRM' AND status = 'ACTIVE' ORDER BY is_primary DESC, id ASC LIMIT 1"
        );
        $statement->execute(['organization_id' => $organizationId]);
        $provider = $statement->fetchColumn();
        if ($provider === false) {
            throw new RuntimeException(sprintf('No active CRM integration configured for organization %s.', $organizationId));
        }
        return (string) $provider;
    }
}
