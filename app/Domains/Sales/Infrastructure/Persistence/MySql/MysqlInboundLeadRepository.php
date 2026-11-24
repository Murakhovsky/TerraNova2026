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

    public function existsForUpdate(string $organizationId,int $leadId): bool
    {
        if (!$this->connection->inTransaction()) throw new \LogicException('Lead readback requires a transaction.');
        $q=$this->connection->prepare('SELECT 1 FROM tn_leads WHERE organization_id=? AND id=? LIMIT 1 FOR UPDATE');
        $q->execute([$organizationId,$leadId]);
        return $q->fetchColumn()!==false;
    }

    public function create(string $organizationId, array $lead): int
    {
        $lead += [
            'request_intent' => 'general_contact',
            'manager_note' => null,
            'assigned_user_id' => null,
            'next_contact_at' => null,
        ];
        $lead['request_intent'] ??= 'general_contact';
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
