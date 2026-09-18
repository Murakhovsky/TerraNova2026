<?php
declare(strict_types=1);

namespace Domains\Sales\Infrastructure\Persistence\MySql;

use Domains\Sales\Application\Contract\InboundLeadRepositoryInterface;
use PDO;

final readonly class MysqlInboundLeadRepository implements InboundLeadRepositoryInterface
{
    public function __construct(private PDO $connection)
    {
    }

    public function create(string $organizationId, array $lead): int
    {
        $lead += [
            'request_intent' => null,
            'manager_note' => null,
            'assigned_user_id' => null,
            'next_contact_at' => null,
        ];
        $statement = $this->connection->prepare('
            INSERT INTO tn_leads (
                organization_id, buyer_id, person_id, client_case_id, property_id, full_name, phone, email,
                role, deal_type, message, preferred_contact, source_page, request_intent, manager_note,
                status, assigned_user_id, next_contact_at
            ) VALUES (
                :organization_id, NULL, :person_id, :client_case_id, :property_id, :full_name, :phone, :email,
                :role, :deal_type, :message, "any", :source_page, :request_intent, :manager_note,
                "new", :assigned_user_id, :next_contact_at
            )
        ');
        $statement->execute(['organization_id' => $organizationId] + $lead);

        return (int) $this->connection->lastInsertId();
    }
}
