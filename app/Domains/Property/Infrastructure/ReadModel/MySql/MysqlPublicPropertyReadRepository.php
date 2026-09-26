<?php
declare(strict_types=1);

namespace Domains\Property\Infrastructure\ReadModel\MySql;

use Domains\Property\Application\Contract\PublicPropertyReadRepositoryInterface;
use Infrastructure\Platform\Persistence\Pdo\PdoConnection;

final readonly class MysqlPublicPropertyReadRepository implements PublicPropertyReadRepositoryInterface
{
    public function __construct(private PdoConnection $database)
    {
    }

    public function search(string $organizationId, array $filters): array
    {
        $conditions = $this->conditions($organizationId, $filters);
        $perPage = $this->perPage($filters);
        $page = max(1, (int) ($filters['page'] ?? 1));
        $offset = ($page - 1) * $perPage;

        $orderBy = match ($filters['sort'] ?? '') {
            'price_asc' => 'p.price_amount IS NULL, p.price_amount ASC, p.id DESC',
            'price_desc' => 'p.price_amount IS NULL, p.price_amount DESC, p.id DESC',
            'area_desc' => 'p.area_total IS NULL, p.area_total DESC, p.id DESC',
            default => 'p.is_featured DESC, p.published_at DESC, p.id DESC',
        };

        return $this->database->fetchAll('
            SELECT
                p.id, p.public_id, p.slug, p.title, p.deal_type, p.status, p.source_type,
                p.price_amount, p.price_currency, p.price_period, p.area_total, p.land_area,
                p.rooms, p.bedrooms, p.bathrooms, p.floor, p.floors, p.built_year,
                p.address, p.latitude, p.longitude, p.short_description,
                p.is_featured, p.has_3d_tour, p.published_at, p.updated_at,
                t.name_uk AS type_name,
                l.city, l.region,
                (
                    SELECT image.image_url
                    FROM tn_property_images image
                    WHERE image.organization_id = p.organization_id
                      AND image.property_id = p.id
                    ORDER BY image.is_cover DESC, image.sort_order, image.id
                    LIMIT 1
                ) AS cover_url,
                (
                    SELECT COUNT(*)
                    FROM tn_property_images image_count
                    WHERE image_count.organization_id = p.organization_id
                      AND image_count.property_id = p.id
                ) AS image_count
            FROM tn_properties p
            INNER JOIN tn_property_types t ON t.id = p.type_id
            INNER JOIN tn_locations l ON l.id = p.location_id
            WHERE ' . implode(' AND ', $conditions['where']) . '
            ORDER BY ' . $orderBy . '
            LIMIT ' . $perPage . ' OFFSET ' . $offset,
            $conditions['params'],
        );
    }

    public function count(string $organizationId, array $filters): int
    {
        $conditions = $this->conditions($organizationId, $filters);
        $row = $this->database->fetchOne('
            SELECT COUNT(DISTINCT p.id) AS total
            FROM tn_properties p
            INNER JOIN tn_property_types t ON t.id = p.type_id
            INNER JOIN tn_locations l ON l.id = p.location_id
            WHERE ' . implode(' AND ', $conditions['where']),
            $conditions['params'],
        );

        return (int) ($row['total'] ?? 0);
    }

    public function stats(string $organizationId, array $filters): array
    {
        $conditions = $this->conditions($organizationId, $filters);
        $row = $this->database->fetchOne('
            SELECT
                COUNT(DISTINCT p.id) AS total,
                MIN(p.price_amount) AS price_min,
                MAX(p.price_amount) AS price_max,
                ROUND(AVG(p.area_total), 1) AS area_avg
            FROM tn_properties p
            INNER JOIN tn_property_types t ON t.id = p.type_id
            INNER JOIN tn_locations l ON l.id = p.location_id
            WHERE ' . implode(' AND ', $conditions['where']),
            $conditions['params'],
        );

        return [
            'total' => (int) ($row['total'] ?? 0),
            'price_min' => $row['price_min'] ?? null,
            'price_max' => $row['price_max'] ?? null,
            'area_avg' => $row['area_avg'] ?? null,
        ];
    }

    public function featured(string $organizationId, int $limit): array
    {
        $limit = max(1, min($limit, 12));

        return $this->database->fetchAll('
            SELECT
                p.id, p.public_id, p.slug, p.title, p.deal_type, p.status, p.source_type,
                p.price_amount, p.price_currency, p.price_period, p.area_total, p.rooms,
                p.address, p.short_description, p.is_featured, p.has_3d_tour, p.published_at,
                t.name_uk AS type_name,
                l.city, l.region,
                (
                    SELECT image.image_url
                    FROM tn_property_images image
                    WHERE image.organization_id = p.organization_id
                      AND image.property_id = p.id
                    ORDER BY image.is_cover DESC, image.sort_order, image.id
                    LIMIT 1
                ) AS cover_url,
                (
                    SELECT COUNT(*)
                    FROM tn_property_images image_count
                    WHERE image_count.organization_id = p.organization_id
                      AND image_count.property_id = p.id
                ) AS image_count
            FROM tn_properties p
            INNER JOIN tn_property_types t ON t.id = p.type_id
            INNER JOIN tn_locations l ON l.id = p.location_id
            WHERE p.organization_id = :organization_id
              AND p.visibility = "public"
              AND p.status IN ("published", "active")
            ORDER BY p.is_featured DESC, p.published_at DESC, p.id DESC
            LIMIT ' . $limit,
            ['organization_id' => $organizationId],
        );
    }

    public function findBySlug(string $organizationId, string $slug): ?array
    {
        return $this->database->fetchOne('
            SELECT
                p.*,
                t.name_uk AS type_name,
                l.city, l.region, l.country_code,
                g.title AS group_title, g.slug AS group_slug, g.group_type,
                g.address AS group_address, g.description AS group_description,
                a.public_name AS agent_name, a.role AS agent_role, a.phone AS agent_phone,
                a.email AS agent_email, a.telegram AS agent_telegram, a.avatar_url AS agent_avatar,
                a.bio AS agent_bio
            FROM tn_properties p
            INNER JOIN tn_property_types t ON t.id = p.type_id
            INNER JOIN tn_locations l ON l.id = p.location_id
            LEFT JOIN tn_property_groups g
              ON g.organization_id = p.organization_id
             AND g.id = p.property_group_id
            LEFT JOIN tn_agents a ON a.id = p.agent_id
            WHERE p.organization_id = :organization_id
              AND p.visibility = "public"
              AND p.status IN ("published", "active")
              AND p.slug = :slug
            LIMIT 1
        ', [
            'organization_id' => $organizationId,
            'slug' => $slug,
        ]);
    }

    public function findByPublicIds(string $organizationId, array $publicIds): array
    {
        $publicIds = array_values(array_slice(array_unique(array_filter(
            $publicIds,
            static fn (mixed $id): bool => is_string($id)
                && $id !== ''
                && preg_match('/^[A-Za-z0-9_-]+$/', $id) === 1,
        )), 0, 100));

        if ($publicIds === []) {
            return [];
        }

        $params = ['organization_id' => $organizationId];
        $placeholders = [];

        foreach ($publicIds as $index => $publicId) {
            $key = 'public_id_' . $index;
            $placeholders[] = ':' . $key;
            $params[$key] = $publicId;
        }

        return $this->database->fetchAll('
            SELECT
                p.id, p.public_id, p.slug, p.title, p.deal_type, p.status, p.source_type,
                p.price_amount, p.price_currency, p.price_period, p.area_total, p.rooms,
                p.address, p.short_description, p.is_featured, p.has_3d_tour, p.published_at,
                t.name_uk AS type_name,
                l.city, l.region,
                (
                    SELECT image.image_url
                    FROM tn_property_images image
                    WHERE image.organization_id = p.organization_id
                      AND image.property_id = p.id
                    ORDER BY image.is_cover DESC, image.sort_order, image.id
                    LIMIT 1
                ) AS cover_url,
                (
                    SELECT COUNT(*)
                    FROM tn_property_images image_count
                    WHERE image_count.organization_id = p.organization_id
                      AND image_count.property_id = p.id
                ) AS image_count
            FROM tn_properties p
            INNER JOIN tn_property_types t ON t.id = p.type_id
            INNER JOIN tn_locations l ON l.id = p.location_id
            WHERE p.organization_id = :organization_id
              AND p.visibility = "public"
              AND p.status IN ("published", "active")
              AND p.public_id IN (' . implode(', ', $placeholders) . ')
            ORDER BY p.is_featured DESC, p.published_at DESC, p.id DESC
        ', $params);
    }

    public function images(string $organizationId, int $propertyId): array
    {
        return $this->database->fetchAll('
            SELECT image_url, alt_text, is_cover
            FROM tn_property_images
            WHERE organization_id = :organization_id
              AND property_id = :property_id
            ORDER BY is_cover DESC, sort_order, id
        ', [
            'organization_id' => $organizationId,
            'property_id' => $propertyId,
        ]);
    }

    public function features(string $organizationId, int $propertyId): array
    {
        return $this->database->fetchAll('
            SELECT feature_key, feature_value
            FROM tn_property_features
            WHERE organization_id = :organization_id
              AND property_id = :property_id
            ORDER BY sort_order, id
        ', [
            'organization_id' => $organizationId,
            'property_id' => $propertyId,
        ]);
    }

    public function related(string $organizationId, array $property, int $limit): array
    {
        $limit = max(1, min($limit, 6));

        return $this->database->fetchAll('
            SELECT
                p.id, p.public_id, p.slug, p.title, p.deal_type, p.status, p.source_type,
                p.price_amount, p.price_currency, p.price_period, p.area_total, p.rooms,
                p.address, p.short_description, p.is_featured, p.has_3d_tour, p.published_at,
                t.name_uk AS type_name,
                l.city, l.region,
                (
                    SELECT image.image_url
                    FROM tn_property_images image
                    WHERE image.organization_id = p.organization_id
                      AND image.property_id = p.id
                    ORDER BY image.is_cover DESC, image.sort_order, image.id
                    LIMIT 1
                ) AS cover_url,
                (
                    SELECT COUNT(*)
                    FROM tn_property_images image_count
                    WHERE image_count.organization_id = p.organization_id
                      AND image_count.property_id = p.id
                ) AS image_count
            FROM tn_properties p
            INNER JOIN tn_property_types t ON t.id = p.type_id
            INNER JOIN tn_locations l ON l.id = p.location_id
            WHERE p.organization_id = :organization_id
              AND p.visibility = "public"
              AND p.status IN ("published", "active")
              AND p.id <> :id
              AND (p.type_id = :type_id OR p.location_id = :location_id OR p.deal_type = :deal_type)
            ORDER BY
                (p.type_id = :order_type_id) DESC,
                (p.location_id = :order_location_id) DESC,
                p.is_featured DESC,
                p.published_at DESC,
                p.id DESC
            LIMIT ' . $limit,
            [
                'organization_id' => $organizationId,
                'id' => (int) $property['id'],
                'type_id' => (int) $property['type_id'],
                'location_id' => (int) $property['location_id'],
                'deal_type' => (string) $property['deal_type'],
                'order_type_id' => (int) $property['type_id'],
                'order_location_id' => (int) $property['location_id'],
            ],
        );
    }

    public function grouped(string $organizationId, array $property, int $limit): array
    {
        $groupId = (int) ($property['property_group_id'] ?? 0);
        if ($groupId <= 0) {
            return [];
        }

        $limit = max(1, min($limit, 12));

        return $this->database->fetchAll('
            SELECT
                p.id, p.public_id, p.slug, p.title, p.deal_type, p.status, p.source_type,
                p.price_amount, p.price_currency, p.price_period, p.area_total, p.rooms,
                p.address, p.short_description, p.is_featured, p.has_3d_tour, p.published_at,
                t.name_uk AS type_name,
                l.city, l.region,
                (
                    SELECT image.image_url
                    FROM tn_property_images image
                    WHERE image.organization_id = p.organization_id
                      AND image.property_id = p.id
                    ORDER BY image.is_cover DESC, image.sort_order, image.id
                    LIMIT 1
                ) AS cover_url,
                (
                    SELECT COUNT(*)
                    FROM tn_property_images image_count
                    WHERE image_count.organization_id = p.organization_id
                      AND image_count.property_id = p.id
                ) AS image_count
            FROM tn_properties p
            INNER JOIN tn_property_types t ON t.id = p.type_id
            INNER JOIN tn_locations l ON l.id = p.location_id
            WHERE p.organization_id = :organization_id
              AND p.visibility = "public"
              AND p.status IN ("published", "active")
              AND p.property_group_id = :group_id
              AND p.id <> :id
            ORDER BY p.is_featured DESC, p.published_at DESC, p.id DESC
            LIMIT ' . $limit,
            [
                'organization_id' => $organizationId,
                'group_id' => $groupId,
                'id' => (int) $property['id'],
            ],
        );
    }

    public function sitemapTypes(string $organizationId): array
    {
        return $this->database->fetchAll('
            SELECT t.code
            FROM tn_property_types t
            INNER JOIN tn_properties p ON p.type_id = t.id
            WHERE t.is_active = 1
              AND p.organization_id = :organization_id
              AND p.visibility = "public"
              AND p.status IN ("published", "active")
            GROUP BY t.id, t.code, t.sort_order
            ORDER BY t.sort_order, t.code
        ', ['organization_id' => $organizationId]);
    }

    public function sitemapLocations(string $organizationId): array
    {
        return $this->database->fetchAll('
            SELECT l.slug
            FROM tn_locations l
            INNER JOIN tn_properties p ON p.location_id = l.id
            WHERE l.is_active = 1
              AND p.organization_id = :organization_id
              AND p.visibility = "public"
              AND p.status IN ("published", "active")
            GROUP BY l.id, l.slug, l.sort_order
            ORDER BY l.sort_order, l.slug
        ', ['organization_id' => $organizationId]);
    }

    public function sitemapLandingPairs(string $organizationId): array
    {
        return $this->database->fetchAll('
            SELECT l.slug AS location_slug, t.code AS type_code
            FROM tn_properties p
            INNER JOIN tn_locations l ON l.id = p.location_id
            INNER JOIN tn_property_types t ON t.id = p.type_id
            WHERE p.organization_id = :organization_id
              AND p.visibility = "public"
              AND p.status IN ("published", "active")
            GROUP BY l.slug, t.code
            ORDER BY COUNT(*) DESC, l.slug, t.code
        ', ['organization_id' => $organizationId]);
    }

    public function sitemapProperties(string $organizationId): array
    {
        return $this->database->fetchAll('
            SELECT slug, updated_at
            FROM tn_properties
            WHERE organization_id = :organization_id
              AND visibility = "public"
              AND status IN ("published", "active")
            ORDER BY updated_at DESC, id DESC
        ', ['organization_id' => $organizationId]);
    }

    /** @return array{where:list<string>,params:array<string,mixed>} */
    private function conditions(string $organizationId, array $filters): array
    {
        $where = [
            'p.organization_id = :organization_id',
            'p.visibility = "public"',
            'p.status IN ("published", "active")',
        ];
        $params = ['organization_id' => $organizationId];

        if (($filters['deal_type'] ?? '') !== '') {
            $where[] = 'p.deal_type = :deal_type';
            $params['deal_type'] = $filters['deal_type'];
        }
        if (($filters['q'] ?? '') !== '') {
            $where[] = '(p.title LIKE :q OR p.public_id LIKE :q OR p.short_description LIKE :q OR p.description LIKE :q OR p.address LIKE :q OR l.city LIKE :q)';
            $params['q'] = '%' . $filters['q'] . '%';
        }
        if (($filters['type'] ?? '') !== '') {
            $where[] = 't.code = :type';
            $params['type'] = $filters['type'];
        }
        if (($filters['location'] ?? '') !== '') {
            $where[] = 'l.slug = :location';
            $params['location'] = $filters['location'];
        }
        if (($filters['price_min'] ?? null) !== null) {
            $where[] = 'p.price_amount >= :price_min';
            $params['price_min'] = $filters['price_min'];
        }
        if (($filters['price_max'] ?? null) !== null) {
            $where[] = 'p.price_amount <= :price_max';
            $params['price_max'] = $filters['price_max'];
        }
        if (($filters['area_min'] ?? null) !== null) {
            $where[] = 'p.area_total >= :area_min';
            $params['area_min'] = $filters['area_min'];
        }
        if (($filters['rooms_min'] ?? null) !== null) {
            $where[] = 'p.rooms >= :rooms_min';
            $params['rooms_min'] = $filters['rooms_min'];
        }

        return ['where' => $where, 'params' => $params];
    }

    private function perPage(array $filters): int
    {
        $value = (int) ($filters['per_page'] ?? 60);
        return in_array($value, [12, 24, 60], true) ? $value : 60;
    }
}
