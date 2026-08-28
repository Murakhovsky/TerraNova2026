<?php
declare(strict_types=1);

namespace Infrastructure\Persistence\MySql\Sales;

use Domains\Sales\Application\Contract\InboundLeadRepositoryInterface;
use PDO;

final readonly class MysqlInboundLeadRepository implements InboundLeadRepositoryInterface
{
    public function __construct(private PDO $connection)
    {
    }

    public function create(string $organizationId, array $lead): int
    {
        $statement = $this->connection->prepare('
            INSERT INTO tn_leads (
                organization_id, buyer_id, person_id, client_case_id, property_id, full_name, phone, email,
                role, deal_type, message, preferred_contact, source_page, status
            ) VALUES (
                :organization_id, NULL, :person_id, :client_case_id, :property_id, :full_name, :phone, :email,
                :role, :deal_type, :message, "any", :source_page, "new"
            )
        ');
        $statement->execute(['organization_id' => $organizationId] + $lead);

        return (int) $this->connection->lastInsertId();
    }
}
