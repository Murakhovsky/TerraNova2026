<?php
declare(strict_types=1);

namespace Infrastructure\Crm;

use Common\Services\DatabaseService;
use Domains\Sales\Crm\Contract\OrganizationCrmResolverInterface;
use RuntimeException;

final readonly class MysqlOrganizationCrmResolver implements OrganizationCrmResolverInterface
{
    public function __construct(private DatabaseService $database)
    {
    }

    public function providerFor(string $organizationId): string
    {
        $integration = $this->database->fetchOne(
            "SELECT provider FROM cos_integrations "
            . "WHERE organization_id = :organization_id AND capability = 'CRM' AND status = 'ACTIVE' "
            . 'ORDER BY is_primary DESC, id ASC LIMIT 1',
            ['organization_id' => $organizationId],
        );

        if (!$integration) {
            throw new RuntimeException(sprintf('No active CRM integration configured for organization %s.', $organizationId));
        }

        return (string) $integration['provider'];
    }
}
