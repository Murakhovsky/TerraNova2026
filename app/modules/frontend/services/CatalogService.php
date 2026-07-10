<?php
declare(strict_types=1);

namespace Modules\Frontend\Services;

use Common\Models\RealEstate\Location;
use Common\Models\RealEstate\PropertyType;
use Common\Services\DatabaseService;

class CatalogService
{
    public function __construct(private DatabaseService $database)
    {
    }

    public function filtersFromQuery(array $query): array
    {
        return [
            'q' => trim((string) ($query['q'] ?? '')),
            'deal_type' => $this->allowed((string) ($query['deal_type'] ?? ''), ['sale', 'rent', 'investment']),
            'type' => trim((string) ($query['type'] ?? '')),
            'location' => trim((string) ($query['location'] ?? '')),
            'status' => $this->allowed((string) ($query['status'] ?? ''), ['published', 'moderation', 'reserved', 'sold']),
            'price_min' => $this->positiveNumber($query['price_min'] ?? null),
            'price_max' => $this->positiveNumber($query['price_max'] ?? null),
            'area_min' => $this->positiveNumber($query['area_min'] ?? null),
            'rooms_min' => $this->positiveNumber($query['rooms_min'] ?? null),
            'sort' => $this->allowed((string) ($query['sort'] ?? ''), ['newest', 'price_asc', 'price_desc', 'area_desc']),
        ];
    }

    public function featuredProperties(int $limit = 3): array
    {
        $limit = max(1, min($limit, 12));

        return $this->database->fetchAll('
            SELECT
                p.id, p.public_id, p.slug, p.title, p.deal_type, p.status,
                p.price_amount, p.price_currency, p.price_period, p.area_total, p.rooms,
                p.short_description, p.is_featured, p.has_3d_tour, p.published_at,
                t.name_uk AS type_name,
                l.city, l.region,
                COALESCE(cover.image_url, first_image.image_url) AS cover_url
            FROM tn_properties p
            INNER JOIN tn_property_types t ON t.id = p.type_id
            INNER JOIN tn_locations l ON l.id = p.location_id
            LEFT JOIN tn_property_images cover ON cover.property_id = p.id AND cover.is_cover = 1
            LEFT JOIN tn_property_images first_image ON first_image.id = (
                SELECT i.id FROM tn_property_images i
                WHERE i.property_id = p.id
                ORDER BY i.sort_order, i.id
                LIMIT 1
            )
            WHERE p.status = :status
            GROUP BY p.id
            ORDER BY p.is_featured DESC, p.published_at DESC, p.id DESC
            LIMIT ' . $limit,
            ['status' => 'published']
        );
    }

    public function catalogProperties(array $filters): array
    {
        $where = ['p.status = :published'];
        $params = ['published' => 'published'];

        if ($filters['deal_type'] !== '') {
            $where[] = 'p.deal_type = :deal_type';
            $params['deal_type'] = $filters['deal_type'];
        }

        if (($filters['q'] ?? '') !== '') {
            $where[] = '(p.title LIKE :q OR p.public_id LIKE :q OR p.short_description LIKE :q OR p.description LIKE :q OR p.address LIKE :q OR l.city LIKE :q)';
            $params['q'] = '%' . $filters['q'] . '%';
        }

        if ($filters['type'] !== '') {
            $where[] = 't.code = :type';
            $params['type'] = $filters['type'];
        }

        if ($filters['location'] !== '') {
            $where[] = 'l.slug = :location';
            $params['location'] = $filters['location'];
        }

        if ($filters['status'] !== '') {
            $where[0] = 'p.status = :status';
            $params['status'] = $filters['status'];
            unset($params['published']);
        }

        if ($filters['price_min'] !== null) {
            $where[] = 'p.price_amount >= :price_min';
            $params['price_min'] = $filters['price_min'];
        }

        if ($filters['price_max'] !== null) {
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
                COALESCE(cover.image_url, first_image.image_url) AS cover_url,
                (
                    SELECT COUNT(*) FROM tn_property_images image_count
                    WHERE image_count.property_id = p.id
                ) AS image_count
            FROM tn_properties p
            INNER JOIN tn_property_types t ON t.id = p.type_id
            INNER JOIN tn_locations l ON l.id = p.location_id
            LEFT JOIN tn_property_images cover ON cover.property_id = p.id AND cover.is_cover = 1
            LEFT JOIN tn_property_images first_image ON first_image.id = (
                SELECT i.id FROM tn_property_images i
                WHERE i.property_id = p.id
                ORDER BY i.sort_order, i.id
                LIMIT 1
            )
            WHERE ' . implode(' AND ', $where) . '
            GROUP BY p.id
            ORDER BY ' . $orderBy . '
            LIMIT 60
        ', $params);
    }

    public function catalogCount(array $filters): int
    {
        $where = ['p.status = :published'];
        $params = ['published' => 'published'];

        if (($filters['q'] ?? '') !== '') {
            $where[] = '(p.title LIKE :q OR p.public_id LIKE :q OR p.short_description LIKE :q OR p.description LIKE :q OR p.address LIKE :q OR l.city LIKE :q)';
            $params['q'] = '%' . $filters['q'] . '%';
        }

        if (($filters['deal_type'] ?? '') !== '') {
            $where[] = 'p.deal_type = :deal_type';
            $params['deal_type'] = $filters['deal_type'];
        }

        if (($filters['status'] ?? '') !== '') {
            $where[0] = 'p.status = :status';
            $params['status'] = $filters['status'];
            unset($params['published']);
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

        $row = $this->database->fetchOne('
            SELECT COUNT(DISTINCT p.id) AS total
            FROM tn_properties p
            INNER JOIN tn_property_types t ON t.id = p.type_id
            INNER JOIN tn_locations l ON l.id = p.location_id
            WHERE ' . implode(' AND ', $where),
            $params
        );

        return (int) ($row['total'] ?? 0);
    }

    public function catalogStats(array $filters): array
    {
        $where = ['p.status = :published'];
        $params = ['published' => 'published'];

        if (($filters['deal_type'] ?? '') !== '') {
            $where[] = 'p.deal_type = :deal_type';
            $params['deal_type'] = $filters['deal_type'];
        }

        if (($filters['q'] ?? '') !== '') {
            $where[] = '(p.title LIKE :q OR p.public_id LIKE :q OR p.short_description LIKE :q OR p.description LIKE :q OR p.address LIKE :q OR l.city LIKE :q)';
            $params['q'] = '%' . $filters['q'] . '%';
        }

        if (($filters['status'] ?? '') !== '') {
            $where[0] = 'p.status = :status';
            $params['status'] = $filters['status'];
            unset($params['published']);
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

        $row = $this->database->fetchOne('
            SELECT
                COUNT(DISTINCT p.id) AS total,
                MIN(p.price_amount) AS price_min,
                MAX(p.price_amount) AS price_max,
                ROUND(AVG(p.area_total), 1) AS area_avg
            FROM tn_properties p
            INNER JOIN tn_property_types t ON t.id = p.type_id
            INNER JOIN tn_locations l ON l.id = p.location_id
            WHERE ' . implode(' AND ', $where),
            $params
        );

        return [
            'total' => (int) ($row['total'] ?? 0),
            'price_min' => $row['price_min'] ?? null,
            'price_max' => $row['price_max'] ?? null,
            'area_avg' => $row['area_avg'] ?? null,
        ];
    }

    public function propertyBySlug(string $slug): ?array
    {
        return $this->database->fetchOne('
            SELECT
                p.*,
                t.name_uk AS type_name,
                l.city, l.region, l.country_code,
                a.public_name AS agent_name, a.role AS agent_role, a.phone AS agent_phone,
                a.email AS agent_email, a.telegram AS agent_telegram, a.avatar_url AS agent_avatar,
                a.bio AS agent_bio
            FROM tn_properties p
            INNER JOIN tn_property_types t ON t.id = p.type_id
            INNER JOIN tn_locations l ON l.id = p.location_id
            LEFT JOIN tn_agents a ON a.id = p.agent_id
            WHERE p.slug = :slug
            LIMIT 1
        ', ['slug' => $slug]);
    }

    public function propertyImages(int $propertyId): array
    {
        return $this->database->fetchAll('
            SELECT image_url, alt_text, is_cover
            FROM tn_property_images
            WHERE property_id = :property_id
            ORDER BY is_cover DESC, sort_order, id
        ', ['property_id' => $propertyId]);
    }

    public function propertyFeatures(int $propertyId): array
    {
        return $this->database->fetchAll('
            SELECT feature_key, feature_value
            FROM tn_property_features
            WHERE property_id = :property_id
            ORDER BY sort_order, id
        ', ['property_id' => $propertyId]);
    }

    public function relatedProperties(array $property, int $limit = 3): array
    {
        $limit = max(1, min($limit, 6));

        $related = $this->database->fetchAll('
            SELECT
                p.id, p.public_id, p.slug, p.title, p.deal_type, p.status,
                p.price_amount, p.price_currency, p.price_period, p.area_total, p.rooms,
                p.short_description, p.is_featured, p.has_3d_tour, p.published_at,
                t.name_uk AS type_name,
                l.city, l.region,
                COALESCE(cover.image_url, first_image.image_url) AS cover_url,
                (
                    SELECT COUNT(*) FROM tn_property_images image_count
                    WHERE image_count.property_id = p.id
                ) AS image_count
            FROM tn_properties p
            INNER JOIN tn_property_types t ON t.id = p.type_id
            INNER JOIN tn_locations l ON l.id = p.location_id
            LEFT JOIN tn_property_images cover ON cover.property_id = p.id AND cover.is_cover = 1
            LEFT JOIN tn_property_images first_image ON first_image.id = (
                SELECT i.id FROM tn_property_images i
                WHERE i.property_id = p.id
                ORDER BY i.sort_order, i.id
                LIMIT 1
            )
            WHERE p.status = "published"
              AND p.id <> :id
              AND (p.type_id = :type_id OR p.location_id = :location_id OR p.deal_type = :deal_type)
            GROUP BY p.id
            ORDER BY
                (p.type_id = :type_id) DESC,
                (p.location_id = :location_id) DESC,
                p.is_featured DESC,
                p.published_at DESC,
                p.id DESC
            LIMIT ' . $limit,
            [
                'id' => (int) $property['id'],
                'type_id' => (int) $property['type_id'],
                'location_id' => (int) $property['location_id'],
                'deal_type' => (string) $property['deal_type'],
            ]
        );

        if ($related) {
            return $related;
        }

        return $this->database->fetchAll('
            SELECT
                p.id, p.public_id, p.slug, p.title, p.deal_type, p.status,
                p.price_amount, p.price_currency, p.price_period, p.area_total, p.rooms,
                p.short_description, p.is_featured, p.has_3d_tour, p.published_at,
                t.name_uk AS type_name,
                l.city, l.region,
                COALESCE(cover.image_url, first_image.image_url) AS cover_url,
                (
                    SELECT COUNT(*) FROM tn_property_images image_count
                    WHERE image_count.property_id = p.id
                ) AS image_count
            FROM tn_properties p
            INNER JOIN tn_property_types t ON t.id = p.type_id
            INNER JOIN tn_locations l ON l.id = p.location_id
            LEFT JOIN tn_property_images cover ON cover.property_id = p.id AND cover.is_cover = 1
            LEFT JOIN tn_property_images first_image ON first_image.id = (
                SELECT i.id FROM tn_property_images i
                WHERE i.property_id = p.id
                ORDER BY i.sort_order, i.id
                LIMIT 1
            )
            WHERE p.status = "published" AND p.id <> :id
            GROUP BY p.id
            ORDER BY p.is_featured DESC, p.published_at DESC, p.id DESC
            LIMIT ' . $limit,
            ['id' => (int) $property['id']]
        );
    }

    public function propertyTypes(): array
    {
        $types = PropertyType::find([
            'conditions' => 'is_active = 1',
            'order' => 'sort_order, name_uk',
        ]);

        $items = [];
        foreach ($types as $type) {
            $items[] = [
                'id' => (int) $type->id,
                'code' => $type->code,
                'name_uk' => $type->name_uk,
            ];
        }

        return $items;
    }

    public function locations(): array
    {
        $locations = Location::find([
            'conditions' => 'is_active = 1',
            'order' => 'sort_order, city',
        ]);

        $items = [];
        foreach ($locations as $location) {
            $items[] = [
                'id' => (int) $location->id,
                'slug' => $location->slug,
                'city' => $location->city,
                'region' => $location->region,
            ];
        }

        return $items;
    }

    private function allowed(string $value, array $allowed): string
    {
        return in_array($value, $allowed, true) ? $value : '';
    }

    private function positiveNumber(mixed $value): ?float
    {
        return is_numeric($value) && (float) $value > 0 ? (float) $value : null;
    }
}
