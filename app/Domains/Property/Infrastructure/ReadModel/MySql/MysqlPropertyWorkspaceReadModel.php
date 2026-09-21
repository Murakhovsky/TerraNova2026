<?php
declare(strict_types=1);

namespace Domains\Property\Infrastructure\ReadModel\MySql;

use Domains\Property\Application\Contract\PropertyWorkspaceReadModelInterface;
use Infrastructure\Platform\Persistence\Pdo\PdoConnection;
use InvalidArgumentException;

final readonly class MysqlPropertyWorkspaceReadModel implements PropertyWorkspaceReadModelInterface
{
    public function __construct(private PdoConnection $database)
    {
    }

    public function overview(string $organizationId, int $limit = 6): array
    {
        $organizationId = $this->organization($organizationId);
        $limit = max(1, min(12, $limit));
        $params = ['organization_id' => $organizationId];

        $stats = $this->database->fetchOne('
            SELECT
                COUNT(*) AS total,
                SUM(status = "published") AS published,
                SUM(status = "active") AS active,
                SUM(status = "reserved") AS reserved,
                SUM(status IN ("published", "active")) AS catalog_total
            FROM tn_properties
            WHERE organization_id = :organization_id
        ', $params) ?? [];

        $featured = $this->database->fetchAll('
            SELECT
                p.id, p.public_id, p.slug, p.title, p.status, p.updated_at,
                t.name_uk AS type_name,
                l.city
            FROM tn_properties p
            INNER JOIN tn_property_types t ON t.id = p.type_id
            INNER JOIN tn_locations l ON l.id = p.location_id
            WHERE p.organization_id = :organization_id
              AND p.status IN ("published", "active", "reserved")
            ORDER BY p.is_featured DESC, p.updated_at DESC, p.id DESC
            LIMIT ' . $limit, $params);

        return [
            'total' => (int) ($stats['total'] ?? 0),
            'catalog_total' => (int) ($stats['catalog_total'] ?? 0),
            'published' => (int) ($stats['published'] ?? 0),
            'active' => (int) ($stats['active'] ?? 0),
            'reserved' => (int) ($stats['reserved'] ?? 0),
            'featured' => $featured,
        ];
    }

    public function inventory(string $organizationId, array $filters = [], int $limit = 100): array
    {
        $organizationId = $this->organization($organizationId);
        $limit = max(1, min(200, $limit));
        $where = ['i.organization_id = :organization_id'];
        $params = ['organization_id' => $organizationId];

        $normalized = [
            'q' => trim((string) ($filters['q'] ?? '')),
            'status' => strtolower(trim((string) ($filters['status'] ?? ''))),
            'transaction_type' => strtolower(trim((string) ($filters['transaction_type'] ?? $filters['deal_type'] ?? ''))),
            'type_code' => trim((string) ($filters['type_code'] ?? '')),
        ];

        if ($normalized['q'] !== '') {
            $where[] = '(a.asset_id LIKE :q OR l.title LIKE :q OR l.slug LIKE :q OR location.name LIKE :q OR address.formatted_address LIKE :q)';
            $params['q'] = '%' . $normalized['q'] . '%';
        }
        if ($normalized['status'] !== '') {
            $where[] = 'i.status = :status';
            $params['status'] = $normalized['status'];
        }
        if (in_array($normalized['transaction_type'], ['sale', 'rent', 'investment'], true)) {
            $where[] = 'i.transaction_type = :transaction_type';
            $params['transaction_type'] = $normalized['transaction_type'];
        }
        if ($normalized['type_code'] !== '') {
            $where[] = 'a.type_code = :type_code';
            $params['type_code'] = $normalized['type_code'];
        }

        $items = $this->database->fetchAll('
            SELECT
                COALESCE(legacy.legacy_property_id, a.legacy_property_id, 0) AS legacy_property_id,
                a.asset_id, a.kind, a.type_code, a.lifecycle,
                i.inventory_id, i.transaction_type, i.status,
                i.price_amount, i.price_currency, i.price_period, i.updated_at,
                l.listing_id, l.title, l.slug, l.status AS listing_status, l.visibility,
                location.name AS location_name,
                address.formatted_address
            FROM tn_property_inventory_items i
            INNER JOIN tn_property_assets a
              ON a.organization_id = i.organization_id
             AND a.asset_id = i.asset_id
            LEFT JOIN tn_property_asset_legacy_links legacy
              ON legacy.organization_id = a.organization_id
             AND legacy.asset_id = a.asset_id
            LEFT JOIN tn_property_listings l
              ON l.organization_id = i.organization_id
             AND l.inventory_id = i.inventory_id
            LEFT JOIN tn_addresses address ON address.id = a.address_id
            LEFT JOIN tn_location_nodes location
              ON location.id = COALESCE(a.location_node_id, address.locality_node_id)
            WHERE ' . implode(' AND ', $where) . '
            ORDER BY i.updated_at DESC, l.updated_at DESC, a.asset_id
            LIMIT ' . $limit,
            $params,
        );

        $statusRows = $this->database->fetchAll('
            SELECT status, COUNT(*) AS total
            FROM tn_property_inventory_items
            WHERE organization_id = :organization_id
            GROUP BY status
            ORDER BY status
        ', ['organization_id' => $organizationId]);

        $stats = ['all' => 0];
        foreach ($statusRows as $row) {
            $count = (int) ($row['total'] ?? 0);
            $stats[(string) ($row['status'] ?? '')] = $count;
            $stats['all'] += $count;
        }

        return ['items' => $items, 'stats' => $stats, 'filters' => $normalized];
    }

    public function submissions(string $organizationId, string $status = '', int $limit = 100): array
    {
        $organizationId = $this->organization($organizationId);
        $status = strtolower(trim($status));
        $limit = max(1, min(200, $limit));
        $params = ['organization_id' => $organizationId];
        $where = ['organization_id = :organization_id'];

        if ($status !== '') {
            $where[] = 'status = :status';
            $params['status'] = $status;
        }

        $items = $this->database->fetchAll('
            SELECT id, submission_ref, status, source_type, deal_type, property_type, title,
                   city, region, district, address, price_amount, price_currency,
                   owner_name, owner_phone, owner_email, preferred_contact,
                   property_id, review_note, reviewed_at, created_at, updated_at
            FROM tn_property_submissions
            WHERE ' . implode(' AND ', $where) . '
            ORDER BY created_at DESC, id DESC
            LIMIT ' . $limit,
            $params,
        );

        $rows = $this->database->fetchAll('
            SELECT status, COUNT(*) AS total
            FROM tn_property_submissions
            WHERE organization_id = :organization_id
            GROUP BY status
        ', ['organization_id' => $organizationId]);

        $counts = [];
        foreach ($rows as $row) {
            $counts[(string) ($row['status'] ?? '')] = (int) ($row['total'] ?? 0);
        }

        return ['items' => $items, 'counts' => $counts];
    }

    public function submission(string $organizationId, int $id): ?array
    {
        if ($id <= 0) {
            return null;
        }

        return $this->database->fetchOne('
            SELECT id, submission_ref, status, source_type, deal_type, property_type, title,
                   city, region, district, address, price_amount, price_currency,
                   area_total, land_area, rooms, floor, floors, built_year, has_3d_tour,
                   media_links, description, features_text,
                   owner_name, owner_phone, owner_email, preferred_contact,
                   property_id, review_note, reviewed_at, created_at, updated_at
            FROM tn_property_submissions
            WHERE organization_id = :organization_id
              AND id = :id
            LIMIT 1
        ', ['organization_id' => $this->organization($organizationId), 'id' => $id]);
    }

    private function organization(string $organizationId): string
    {
        $organizationId = trim($organizationId);
        if ($organizationId === '') {
            throw new InvalidArgumentException('Organization id is required.');
        }
        return $organizationId;
    }
}
