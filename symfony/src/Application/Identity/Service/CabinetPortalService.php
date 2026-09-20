<?php
declare(strict_types=1);

namespace App\Application\Identity\Service;

use Kernel\Tenant\Model\TenantContext;
use PDO;

final readonly class CabinetPortalService
{
    public function __construct(private PDO $connection)
    {
    }

    /** @return array<string,mixed>|null */
    public function user(TenantContext $tenant): ?array
    {
        $statement = $this->connection->prepare(<<<'SQL'
SELECT
    u.id,
    u.organization_id,
    u.email,
    u.full_name,
    u.phone,
    u.role,
    u.status,
    u.last_login_at,
    u.created_at,
    m.role AS organization_role
FROM tn_users u
INNER JOIN cos_organization_memberships m
    ON m.user_id = u.id
   AND m.organization_id = :organization_id
   AND m.status = 'ACTIVE'
WHERE u.id = :user_id
  AND u.status = 'active'
LIMIT 1
SQL);
        $statement->execute([
            'organization_id' => $tenant->organizationId()->value(),
            'user_id' => (int) $tenant->userId()->value(),
        ]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);

        return is_array($row) ? $row : null;
    }

    /** @return array{my_properties:list<array<string,mixed>>,submissions:list<array<string,mixed>>,inbound_requests:list<array<string,mixed>>} */
    public function dashboard(TenantContext $tenant, array $user): array
    {
        $email = (string) ($user['email'] ?? '');
        $organizationId = $tenant->organizationId()->value();

        return [
            'my_properties' => $this->all(<<<'SQL'
SELECT DISTINCT
    p.id, p.public_id, p.slug, p.title, p.status, p.price_amount, p.price_currency,
    p.updated_at, l.city, t.name_uk AS type_name
FROM tn_property_submissions s
INNER JOIN tn_properties p
    ON p.id = s.property_id
   AND p.organization_id = s.organization_id
INNER JOIN tn_locations l ON l.id = p.location_id
INNER JOIN tn_property_types t ON t.id = p.type_id
WHERE s.organization_id = :organization_id
  AND LOWER(s.owner_email) = :email
ORDER BY p.updated_at DESC, p.id DESC
LIMIT 40
SQL, ['organization_id' => $organizationId, 'email' => mb_strtolower($email)]),
            'submissions' => $this->all(<<<'SQL'
SELECT
    s.id, s.submission_ref, s.title, s.status, s.city, s.property_id, s.created_at,
    p.slug AS property_slug, p.title AS property_title
FROM tn_property_submissions s
LEFT JOIN tn_properties p
    ON p.id = s.property_id
   AND p.organization_id = s.organization_id
WHERE s.organization_id = :organization_id
  AND LOWER(s.owner_email) = :email
ORDER BY s.created_at DESC, s.id DESC
LIMIT 20
SQL, ['organization_id' => $organizationId, 'email' => mb_strtolower($email)]),
            'inbound_requests' => $this->all(<<<'SQL'
SELECT
    l.id, l.role, l.deal_type, l.message, l.status, l.source_page, l.created_at,
    p.slug AS property_slug, p.title AS property_title
FROM tn_leads l
LEFT JOIN tn_properties p
    ON p.id = l.property_id
   AND p.organization_id = l.organization_id
WHERE l.organization_id = :organization_id
  AND LOWER(l.email) = :email
ORDER BY l.created_at DESC, l.id DESC
LIMIT 20
SQL, ['organization_id' => $organizationId, 'email' => mb_strtolower($email)]),
        ];
    }

    /** @return array<string,array<string,bool>> */
    public function roleCapabilities(): array
    {
        return [
            'buyer' => ['catalog' => true, 'cabinet' => true, 'submit_property' => false, 'listing' => false, 'crm' => false, 'admin' => false],
            'seller' => ['catalog' => true, 'cabinet' => true, 'submit_property' => true, 'listing' => false, 'crm' => false, 'admin' => false],
            'investor' => ['catalog' => true, 'cabinet' => true, 'submit_property' => false, 'listing' => false, 'crm' => false, 'admin' => false],
            'realtor' => ['catalog' => true, 'cabinet' => true, 'submit_property' => true, 'listing' => true, 'crm' => false, 'admin' => false],
            'developer' => ['catalog' => true, 'cabinet' => true, 'submit_property' => true, 'listing' => true, 'crm' => false, 'admin' => false],
            'partner' => ['catalog' => true, 'cabinet' => true, 'submit_property' => true, 'listing' => true, 'crm' => false, 'admin' => false],
            'manager' => ['catalog' => true, 'cabinet' => true, 'submit_property' => true, 'listing' => true, 'crm' => true, 'admin' => false],
            'admin' => ['catalog' => true, 'cabinet' => true, 'submit_property' => true, 'listing' => true, 'crm' => true, 'admin' => true],
        ];
    }

    /** @param array<string,mixed> $params @return list<array<string,mixed>> */
    private function all(string $sql, array $params): array
    {
        $statement = $this->connection->prepare($sql);
        $statement->execute($params);
        $rows = $statement->fetchAll(PDO::FETCH_ASSOC);

        return is_array($rows) ? array_values($rows) : [];
    }
}
