<?php
declare(strict_types=1);

namespace Infrastructure\Persistence\MySql\Sales;

use Domains\Sales\Application\Contract\ClientCaseCommandRepositoryInterface;
use InvalidArgumentException;
use PDO;

final readonly class MysqlClientCaseCommandRepository implements ClientCaseCommandRepositoryInterface
{
    public function __construct(private PDO $connection)
    {
    }

    public function activeManagerId(string $organizationId, mixed $value): ?int
    {
        $id = (int) $value;
        if ($id <= 0) return null;
        $statement = $this->connection->prepare('SELECT u.id FROM tn_users u
            INNER JOIN cos_organization_memberships membership ON membership.user_id = u.id
                AND membership.organization_id = :organization_id AND membership.status = "ACTIVE"
            WHERE u.id = :id AND u.status = "active" AND membership.role IN ("manager", "admin") LIMIT 1');
        $statement->execute(['id' => $id, 'organization_id' => $organizationId]);
        return $statement->fetchColumn() !== false ? $id : null;
    }

    public function activePropertyTypeId(mixed $value): ?int
    {
        return $this->activeReferenceId('tn_property_types', $value);
    }

    public function activeLocationId(mixed $value): ?int
    {
        return $this->activeReferenceId('tn_locations', $value);
    }

    public function property(int $propertyId, bool $activeOnly = false): ?array
    {
        if ($propertyId <= 0) return null;
        $sql = 'SELECT id, public_id, title, slug, type_id, location_id, price_amount, price_currency, status
            FROM tn_properties WHERE id = :id';
        if ($activeOnly) $sql .= ' AND status IN ("published", "reserved")';
        return $this->one($sql . ' LIMIT 1', ['id' => $propertyId]);
    }

    public function propertyMatch(string $organizationId, int $matchId): ?array
    {
        return $this->one('SELECT m.*, p.public_id, p.title FROM tn_client_case_property_matches m
            INNER JOIN tn_properties p ON p.id = m.property_id
            INNER JOIN tn_client_cases c ON c.id = m.client_case_id AND c.organization_id = m.organization_id
            WHERE m.id = :id AND m.organization_id = :organization_id LIMIT 1',
            ['id' => $matchId, 'organization_id' => $organizationId]);
    }

    public function inboundRequest(string $organizationId, int $requestId): ?array
    {
        return $this->one('SELECT l.*, p.type_id AS property_type_id, p.location_id,
                p.public_id AS property_public_id, p.title AS property_title
            FROM tn_leads l LEFT JOIN tn_properties p ON p.id = l.property_id
            WHERE l.id = :id AND l.organization_id = :organization_id LIMIT 1',
            ['id' => $requestId, 'organization_id' => $organizationId]);
    }

    public function findPerson(string $organizationId, ?string $email, ?string $phone): ?array
    {
        if ($email !== null && $email !== '') {
            $person = $this->one('SELECT id FROM tn_people WHERE email = :email AND organization_id = :organization_id LIMIT 1',
                ['email' => $email, 'organization_id' => $organizationId]);
            if ($person) return $person;
        }
        if ($phone !== null && $phone !== '') {
            return $this->one('SELECT id FROM tn_people WHERE phone = :phone AND organization_id = :organization_id LIMIT 1',
                ['phone' => $phone, 'organization_id' => $organizationId]);
        }
        return null;
    }

    public function createPerson(string $organizationId, array $person): int
    {
        $statement = $this->connection->prepare('INSERT INTO tn_people
            (organization_id, public_id, full_name, phone, email, telegram, notes)
            VALUES (:organization_id, :public_id, :full_name, :phone, :email, :telegram, :notes)');
        $statement->execute(['organization_id' => $organizationId, 'public_id' => $this->nextPublicId($organizationId, 'tn_people', 'PN')] + $person);
        return (int) $this->connection->lastInsertId();
    }

    public function refreshPerson(string $organizationId, int $personId, array $person): bool
    {
        $statement = $this->connection->prepare('UPDATE tn_people
            SET full_name=COALESCE(NULLIF(:full_name,""),full_name), phone=COALESCE(:phone,phone),
                email=COALESCE(:email,email), telegram=COALESCE(:telegram,telegram)
            WHERE id=:id AND organization_id=:organization_id');
        $statement->execute(['id' => $personId, 'organization_id' => $organizationId] + $person);
        return $this->exists('tn_people', $organizationId, $personId);
    }

    public function updatePerson(string $organizationId, int $personId, array $person): bool
    {
        $statement = $this->connection->prepare('UPDATE tn_people
            SET full_name=:full_name, phone=:phone, email=:email, telegram=:telegram, notes=:notes
            WHERE id=:id AND organization_id=:organization_id');
        $statement->execute(['id' => $personId, 'organization_id' => $organizationId] + $person);
        return $this->exists('tn_people', $organizationId, $personId);
    }

    public function createCase(string $organizationId, int $personId, array $case): int
    {
        $statement = $this->connection->prepare('INSERT INTO tn_client_cases
            (organization_id,public_id,person_id,type,title,status,stage,priority,assigned_user_id,source,
             property_type_id,location_id,budget_min,budget_max,currency,area_min,area_max,description,parameters_json,started_at,next_contact_at,closed_at)
            VALUES (:organization_id,:public_id,:person_id,:type,:title,:status,:stage,:priority,:assigned_user_id,:source,
             :property_type_id,:location_id,:budget_min,:budget_max,:currency,:area_min,:area_max,:description,:parameters_json,NOW(),:next_contact_at,:closed_at)');
        $statement->execute([
            'organization_id' => $organizationId,
            'public_id' => $this->nextPublicId($organizationId, 'tn_client_cases', 'CC'),
            'person_id' => $personId,
        ] + $case);
        return (int) $this->connection->lastInsertId();
    }

    public function updateCase(string $organizationId, int $caseId, array $case): bool
    {
        $statement = $this->connection->prepare('UPDATE tn_client_cases SET
            title=:title,type=:type,status=:status,stage=:stage,priority=:priority,assigned_user_id=:assigned_user_id,
            source=:source,property_type_id=:property_type_id,location_id=:location_id,budget_min=:budget_min,
            budget_max=:budget_max,currency=:currency,area_min=:area_min,area_max=:area_max,description=:description,
            parameters_json=:parameters_json,next_contact_at=:next_contact_at,closed_at=:closed_at,updated_at=NOW()
            WHERE id=:id AND organization_id=:organization_id');
        $statement->execute(['id' => $caseId, 'organization_id' => $organizationId] + $case);
        return $this->exists('tn_client_cases', $organizationId, $caseId);
    }

    public function quickUpdate(string $organizationId, int $caseId, array $changes): bool
    {
        $statement = $this->connection->prepare('UPDATE tn_client_cases SET status=:status,stage=:stage,
            priority=:priority,assigned_user_id=:assigned_user_id,next_contact_at=:next_contact_at,
            closed_at=:closed_at,updated_at=NOW() WHERE id=:id AND organization_id=:organization_id');
        $statement->execute(['id' => $caseId, 'organization_id' => $organizationId] + $changes);
        return $this->exists('tn_client_cases', $organizationId, $caseId);
    }

    public function addActivity(string $organizationId, int $caseId, int $personId, ?int $userId, array $activity): int
    {
        $statement = $this->connection->prepare('INSERT INTO tn_client_case_activities
            (organization_id,client_case_id,person_id,user_id,activity_type,title,body,due_at,completed_at)
            SELECT :organization_id,:client_case_id,:person_id,:user_id,:activity_type,:title,:body,:due_at,:completed_at
            FROM tn_client_cases WHERE id=:client_case_id_check AND organization_id=:organization_id_check');
        $statement->execute([
            'organization_id' => $organizationId, 'client_case_id' => $caseId, 'person_id' => $personId, 'user_id' => $userId,
            'client_case_id_check' => $caseId, 'organization_id_check' => $organizationId,
        ] + $activity);
        if ($statement->rowCount() === 0) throw new \RuntimeException('Client case does not belong to the organization.');
        return (int) $this->connection->lastInsertId();
    }

    public function clearNextContact(string $organizationId, int $caseId): void
    {
        $statement = $this->connection->prepare('UPDATE tn_client_cases SET next_contact_at=NULL
            WHERE id=:id AND organization_id=:organization_id');
        $statement->execute(['id' => $caseId, 'organization_id' => $organizationId]);
    }

    public function updateInboundRequest(string $organizationId, int $requestId, array $changes): bool
    {
        $statement = $this->connection->prepare('UPDATE tn_leads SET status=:status,assigned_user_id=:assigned_user_id,
            manager_note=:manager_note,last_contacted_at=:last_contacted_at,next_contact_at=:next_contact_at,updated_at=NOW()
            WHERE id=:id AND organization_id=:organization_id');
        $statement->execute(['id' => $requestId, 'organization_id' => $organizationId] + $changes);
        return $this->exists('tn_leads', $organizationId, $requestId);
    }

    public function addLeadActivity(string $organizationId, int $requestId, ?int $userId, array $activity): int
    {
        $statement = $this->connection->prepare('INSERT INTO tn_lead_activities
            (lead_id,user_id,activity_type,title,body,due_at,completed_at)
            SELECT id,:user_id,:activity_type,:title,:body,:due_at,:completed_at FROM tn_leads
            WHERE id=:request_id AND organization_id=:organization_id');
        $statement->execute(['request_id' => $requestId, 'organization_id' => $organizationId, 'user_id' => $userId] + $activity);
        if ($statement->rowCount() === 0) throw new \RuntimeException('Inbound request does not belong to the organization.');
        return (int) $this->connection->lastInsertId();
    }

    public function syncCaseFromLead(string $organizationId, int $caseId, array $changes): bool
    {
        $statement = $this->connection->prepare('UPDATE tn_client_cases SET stage=:stage,status=:status,
            assigned_user_id=COALESCE(:assigned_user_id,assigned_user_id),next_contact_at=:next_contact_at,
            closed_at=:closed_at,updated_at=NOW() WHERE id=:id AND organization_id=:organization_id');
        $statement->execute(['id' => $caseId, 'organization_id' => $organizationId] + $changes);
        return $this->exists('tn_client_cases', $organizationId, $caseId);
    }

    public function attachInboundRequest(string $organizationId, int $caseId, int $personId, int $requestId, ?int $userId): bool
    {
        if (!$this->qualifyInboundRequest($organizationId, $caseId, $personId, $requestId, $userId)) return false;
        return $this->insertRequestMatch($organizationId, $caseId, $requestId, 'context');
    }

    public function registerInboundRequest(string $organizationId, int $caseId, int $requestId): bool
    {
        if (!$this->exists('tn_leads', $organizationId, $requestId)) return false;
        $statement = $this->connection->prepare('UPDATE tn_client_cases
            SET inbound_request_id=COALESCE(inbound_request_id,:request_id)
            WHERE id=:case_id AND organization_id=:organization_id');
        $statement->execute(['case_id' => $caseId, 'request_id' => $requestId, 'organization_id' => $organizationId]);
        if (!$this->exists('tn_client_cases', $organizationId, $caseId)) return false;
        return $this->insertRequestMatch($organizationId, $caseId, $requestId, 'source');
    }

    public function upsertPropertyMatch(string $organizationId, int $caseId, int $propertyId, array $match): bool
    {
        if (!$this->exists('tn_client_cases', $organizationId, $caseId)) return false;
        $statement = $this->connection->prepare('INSERT INTO tn_client_case_property_matches
            (organization_id,client_case_id,property_id,match_status,score,note)
            VALUES (:organization_id,:client_case_id,:property_id,:match_status,:score,:note)
            ON DUPLICATE KEY UPDATE match_status=VALUES(match_status),score=VALUES(score),note=VALUES(note),updated_at=NOW()');
        $statement->execute(['organization_id' => $organizationId, 'client_case_id' => $caseId, 'property_id' => $propertyId] + $match);
        return true;
    }

    public function updatePropertyMatch(string $organizationId, int $matchId, array $match): bool
    {
        $statement = $this->connection->prepare('UPDATE tn_client_case_property_matches
            SET match_status=:match_status,score=:score,note=:note,updated_at=NOW()
            WHERE id=:id AND organization_id=:organization_id');
        $statement->execute(['id' => $matchId, 'organization_id' => $organizationId] + $match);
        return $this->propertyMatch($organizationId, $matchId) !== null;
    }

    private function qualifyInboundRequest(string $organizationId, int $caseId, int $personId, int $requestId, ?int $userId): bool
    {
        if (!$this->exists('tn_client_cases', $organizationId, $caseId)) return false;
        $statement = $this->connection->prepare('UPDATE tn_leads SET person_id=:person_id,client_case_id=:client_case_id,
            assigned_user_id=COALESCE(:assigned_user_id,assigned_user_id),status="qualified",updated_at=NOW()
            WHERE id=:request_id AND organization_id=:organization_id');
        $statement->execute([
            'person_id' => $personId, 'client_case_id' => $caseId, 'assigned_user_id' => $userId,
            'request_id' => $requestId, 'organization_id' => $organizationId,
        ]);
        return $this->exists('tn_leads', $organizationId, $requestId);
    }

    private function insertRequestMatch(string $organizationId, int $caseId, int $requestId, string $relationType): bool
    {
        $statement = $this->connection->prepare('INSERT INTO tn_client_case_request_matches
            (organization_id,client_case_id,inbound_request_id,relation_type)
            VALUES (:organization_id,:case_id,:request_id,:relation_type)
            ON DUPLICATE KEY UPDATE relation_type=VALUES(relation_type)');
        $statement->execute([
            'organization_id' => $organizationId, 'case_id' => $caseId,
            'request_id' => $requestId, 'relation_type' => $relationType,
        ]);
        return true;
    }

    private function activeReferenceId(string $table, mixed $value): ?int
    {
        if (!in_array($table, ['tn_property_types', 'tn_locations'], true)) {
            throw new InvalidArgumentException('Unsupported reference table.');
        }
        $id = (int) $value;
        if ($id <= 0) return null;
        $statement = $this->connection->prepare(sprintf('SELECT id FROM %s WHERE id=:id AND is_active=1 LIMIT 1', $table));
        $statement->execute(['id' => $id]);
        return $statement->fetchColumn() !== false ? $id : null;
    }

    private function nextPublicId(string $organizationId, string $table, string $prefix): string
    {
        if (!in_array($table, ['tn_client_cases', 'tn_people'], true)) {
            throw new InvalidArgumentException('Unsupported public id table.');
        }
        $statement = $this->connection->prepare(sprintf(
            'SELECT COALESCE(MAX(CAST(SUBSTRING(public_id,%d) AS UNSIGNED)),0)+1 FROM %s WHERE public_id LIKE :pattern AND organization_id=:organization_id',
            strlen($prefix) + 2, $table,
        ));
        $statement->execute(['pattern' => $prefix . '-%', 'organization_id' => $organizationId]);
        return sprintf('%s-%05d', $prefix, (int) $statement->fetchColumn());
    }

    private function exists(string $table, string $organizationId, int $id): bool
    {
        if (!in_array($table, ['tn_people', 'tn_client_cases', 'tn_leads'], true)) {
            throw new InvalidArgumentException('Unsupported tenant table.');
        }
        $statement = $this->connection->prepare(sprintf(
            'SELECT 1 FROM %s WHERE id=:id AND organization_id=:organization_id LIMIT 1', $table,
        ));
        $statement->execute(['id' => $id, 'organization_id' => $organizationId]);
        return $statement->fetchColumn() !== false;
    }

    private function one(string $sql, array $parameters): ?array
    {
        $statement = $this->connection->prepare($sql);
        $statement->execute($parameters);
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? $row : null;
    }
}
