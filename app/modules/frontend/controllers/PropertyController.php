<?php
declare(strict_types=1);

namespace Modules\Frontend\Controllers;

use PDO;

class PropertyController extends ControllerBase
{
    public function catalogAction(): void
    {
        $pdo = $this->getPdo();
        $filters = $this->catalogFilters();
        $this->view->leadStatus = null;

        if ($this->request->isPost()) {
            $this->view->leadStatus = $this->storeLead($pdo);
        }

        $this->view->types = $this->fetchTypes($pdo);
        $this->view->locations = $this->fetchLocations($pdo);
        $this->view->filters = $filters;
        $this->view->properties = $this->fetchCatalogProperties($pdo, $filters);
    }

    public function showAction(?string $slug = null): void
    {
        $pdo = $this->getPdo();
        $slug = $slug ?: (string) $this->dispatcher->getParam('slug');
        $this->view->leadStatus = null;

        if ($this->request->isPost()) {
            $this->view->leadStatus = $this->storeLead($pdo);
        }

        $property = $this->fetchProperty($pdo, $slug);

        if (!$property) {
            $this->response->setStatusCode(404, 'Not Found');
            $this->view->property = null;
            $this->view->images = [];
            $this->view->features = [];
            return;
        }

        $this->view->property = $property;
        $this->view->images = $this->fetchPropertyImages($pdo, (int) $property['id']);
        $this->view->features = $this->fetchPropertyFeatures($pdo, (int) $property['id']);
    }

    public function createAction(): void
    {
        $this->view->mode = 'create';
        $this->view->title = 'Додати об’єкт';
    }

    public function editAction(?string $id = null): void
    {
        $this->view->mode = 'edit';
        $this->view->title = 'Редагувати об’єкт';
        $this->view->propertyId = $id ?: (string) $this->dispatcher->getParam('id');
    }

    public function favourAction(): void
    {
        $this->view->title = 'Вибрані об’єкти';
    }

    private function catalogFilters(): array
    {
        return [
            'deal_type' => $this->allowedQuery('deal_type', ['sale', 'rent', 'investment']),
            'type' => trim((string) $this->request->getQuery('type', 'string', '')),
            'location' => trim((string) $this->request->getQuery('location', 'string', '')),
            'status' => $this->allowedQuery('status', ['published', 'moderation', 'reserved', 'sold']),
            'price_min' => $this->positiveNumber('price_min'),
            'price_max' => $this->positiveNumber('price_max'),
        ];
    }

    private function allowedQuery(string $name, array $allowed): string
    {
        $value = (string) $this->request->getQuery($name, 'string', '');

        return in_array($value, $allowed, true) ? $value : '';
    }

    private function positiveNumber(string $name): ?float
    {
        $value = $this->request->getQuery($name, 'float', null);

        return is_numeric($value) && (float) $value > 0 ? (float) $value : null;
    }

    private function fetchTypes(PDO $pdo): array
    {
        return $pdo
            ->query('SELECT code, name_uk FROM tn_property_types WHERE is_active = 1 ORDER BY sort_order, name_uk')
            ->fetchAll();
    }

    private function fetchLocations(PDO $pdo): array
    {
        return $pdo
            ->query('SELECT slug, city, region FROM tn_locations WHERE is_active = 1 ORDER BY sort_order, city')
            ->fetchAll();
    }

    private function fetchCatalogProperties(PDO $pdo, array $filters): array
    {
        $where = ['p.status = :published'];
        $params = ['published' => 'published'];

        if ($filters['deal_type'] !== '') {
            $where[] = 'p.deal_type = :deal_type';
            $params['deal_type'] = $filters['deal_type'];
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

        $sql = '
            SELECT
                p.id, p.public_id, p.slug, p.title, p.deal_type, p.status, p.source_type,
                p.price_amount, p.price_currency, p.price_period, p.area_total, p.land_area,
                p.rooms, p.bedrooms, p.bathrooms, p.floor, p.floors, p.short_description,
                p.is_featured, p.has_3d_tour, p.published_at,
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
            WHERE ' . implode(' AND ', $where) . '
            GROUP BY p.id
            ORDER BY p.is_featured DESC, p.published_at DESC, p.id DESC
            LIMIT 60
        ';

        $statement = $pdo->prepare($sql);
        $statement->execute($params);

        return $statement->fetchAll();
    }

    private function fetchProperty(PDO $pdo, string $slug): ?array
    {
        $statement = $pdo->prepare('
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
        ');
        $statement->execute(['slug' => $slug]);
        $property = $statement->fetch();

        return $property ?: null;
    }

    private function fetchPropertyImages(PDO $pdo, int $propertyId): array
    {
        $statement = $pdo->prepare('
            SELECT image_url, alt_text, is_cover
            FROM tn_property_images
            WHERE property_id = :property_id
            ORDER BY is_cover DESC, sort_order, id
        ');
        $statement->execute(['property_id' => $propertyId]);

        return $statement->fetchAll();
    }

    private function fetchPropertyFeatures(PDO $pdo, int $propertyId): array
    {
        $statement = $pdo->prepare('
            SELECT feature_key, feature_value
            FROM tn_property_features
            WHERE property_id = :property_id
            ORDER BY sort_order, id
        ');
        $statement->execute(['property_id' => $propertyId]);

        return $statement->fetchAll();
    }
}
