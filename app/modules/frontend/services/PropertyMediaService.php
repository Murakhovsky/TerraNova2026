<?php
declare(strict_types=1);

namespace Modules\Frontend\Services;

use Common\Services\DatabaseService;
use Common\Services\MediaStorageService;
use PDO;
use Throwable;

class PropertyMediaService
{
    public function __construct(private DatabaseService $database, private MediaStorageService $mediaStorage)
    {
    }

    public function property(int $id): ?array
    {
        return $this->database->fetchOne('
            SELECT p.*,
                   t.name_uk AS type_name,
                   l.city, l.region,
                   a.public_name AS agent_name
            FROM tn_properties p
            INNER JOIN tn_property_types t ON t.id = p.type_id
            INNER JOIN tn_locations l ON l.id = p.location_id
            LEFT JOIN tn_agents a ON a.id = p.agent_id
            WHERE p.id = :id
            LIMIT 1
        ', ['id' => $id]);
    }

    public function images(int $propertyId): array
    {
        return $this->database->fetchAll('
            SELECT id, image_url, alt_text, sort_order, is_cover, created_at
            FROM tn_property_images
            WHERE property_id = :property_id
            ORDER BY is_cover DESC, sort_order, id
        ', ['property_id' => $propertyId]);
    }

    public function adminFilters(array $query): array
    {
        return [
            'q' => trim((string) ($query['q'] ?? '')),
            'status' => $this->allowed((string) ($query['status'] ?? ''), ['draft', 'moderation', 'published', 'reserved', 'sold', 'archived'], ''),
            'deal_type' => $this->allowed((string) ($query['deal_type'] ?? ''), ['sale', 'rent', 'investment'], ''),
            'type_id' => max(0, (int) ($query['type_id'] ?? 0)),
            'location_id' => max(0, (int) ($query['location_id'] ?? 0)),
            'source_type' => $this->allowed((string) ($query['source_type'] ?? ''), ['own', 'partner', 'realtor', 'owner', 'developer'], ''),
            'sort' => $this->allowed((string) ($query['sort'] ?? ''), ['newest', 'updated', 'price_desc', 'price_asc'], 'updated'),
        ];
    }

    public function adminProperties(array $filters): array
    {
        $where = ['1 = 1'];
        $params = [];

        if (($filters['q'] ?? '') !== '') {
            $where[] = '(p.title LIKE :q OR p.public_id LIKE :q OR p.slug LIKE :q OR p.address LIKE :q OR l.city LIKE :q)';
            $params['q'] = '%' . $filters['q'] . '%';
        }

        foreach (['status', 'deal_type', 'source_type'] as $field) {
            if (($filters[$field] ?? '') !== '') {
                $where[] = 'p.' . $field . ' = :' . $field;
                $params[$field] = $filters[$field];
            }
        }

        foreach (['type_id', 'location_id'] as $field) {
            if ((int) ($filters[$field] ?? 0) > 0) {
                $where[] = 'p.' . $field . ' = :' . $field;
                $params[$field] = (int) $filters[$field];
            }
        }

        $orderBy = match ($filters['sort'] ?? 'updated') {
            'newest' => 'p.id DESC',
            'price_desc' => 'p.price_amount IS NULL, p.price_amount DESC, p.updated_at DESC',
            'price_asc' => 'p.price_amount IS NULL, p.price_amount ASC, p.updated_at DESC',
            default => 'p.updated_at DESC, p.id DESC',
        };

        return $this->database->fetchAll('
            SELECT
                p.id, p.public_id, p.slug, p.title, p.deal_type, p.status, p.source_type,
                p.price_amount, p.price_currency, p.price_period, p.area_total, p.rooms,
                p.is_featured, p.has_3d_tour, p.published_at, p.updated_at,
                t.name_uk AS type_name,
                l.city, l.region,
                COALESCE(cover.image_url, first_image.image_url) AS cover_url,
                (
                    SELECT COUNT(*) FROM tn_property_images image_count
                    WHERE image_count.property_id = p.id
                ) AS image_count,
                (
                    SELECT COUNT(*) FROM tn_leads inbound_request_count
                    WHERE inbound_request_count.property_id = p.id
                ) AS inbound_request_count
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
            LIMIT 150
        ', $params);
    }

    public function adminStats(): array
    {
        $rows = $this->database->fetchAll('
            SELECT status, COUNT(*) AS total
            FROM tn_properties
            GROUP BY status
        ');

        $stats = [
            'all' => 0,
            'draft' => 0,
            'moderation' => 0,
            'published' => 0,
            'reserved' => 0,
            'sold' => 0,
            'archived' => 0,
        ];

        foreach ($rows as $row) {
            $status = (string) ($row['status'] ?? '');
            $total = (int) ($row['total'] ?? 0);
            if (array_key_exists($status, $stats)) {
                $stats[$status] = $total;
            }
            $stats['all'] += $total;
        }

        return $stats;
    }

    public function updateStatus(int $propertyId, string $status): array
    {
        $status = $this->allowed($status, ['draft', 'moderation', 'published', 'reserved', 'sold', 'archived'], '');
        if ($status === '') {
            return ['ok' => false, 'message' => 'Невідомий статус об’єкта.'];
        }

        try {
            $statement = $this->database->connection()->prepare('
                UPDATE tn_properties
                SET status = :status,
                    published_at = CASE
                        WHEN :status_for_publish = "published" AND published_at IS NULL THEN NOW()
                        ELSE published_at
                    END,
                    updated_at = NOW()
                WHERE id = :id
                LIMIT 1
            ');
            $statement->execute([
                'id' => $propertyId,
                'status' => $status,
                'status_for_publish' => $status,
            ]);

            return $statement->rowCount() > 0
                ? ['ok' => true, 'message' => 'Статус об’єкта оновлено.']
                : ['ok' => false, 'message' => 'Об’єкт не знайдено або статус не змінився.'];
        } catch (Throwable $e) {
            $this->logError('property-status-update', $e);

            return ['ok' => false, 'message' => 'Не вдалося оновити статус. Деталі записано в лог.'];
        }
    }

    public function updateDetails(int $propertyId, array $input): array
    {
        $pdo = $this->database->connection();

        try {
            $pdo->beginTransaction();

            $property = $this->propertyForUpdate($pdo, $propertyId);
            if (!$property) {
                $pdo->rollBack();

                return ['ok' => false, 'message' => 'Об’єкт не знайдено.'];
            }

            $title = $this->limit((string) ($input['title'] ?? ''), 220);
            if ($title === '') {
                $pdo->rollBack();

                return ['ok' => false, 'message' => 'Назва об’єкта обов’язкова.'];
            }

            $slug = $this->slugValue((string) ($input['slug'] ?? $title));
            if ($slug === '') {
                $slug = 'property-' . $propertyId;
            }

            $data = [
                'id' => $propertyId,
                'slug' => $this->uniqueSlug($pdo, $slug, $propertyId),
                'title' => $title,
                'deal_type' => $this->allowed((string) ($input['deal_type'] ?? $property['deal_type']), ['sale', 'rent', 'investment'], (string) $property['deal_type']),
                'type_id' => $this->existingTypeId($pdo, (int) ($input['type_id'] ?? 0), (int) $property['type_id']),
                'status' => $this->allowed((string) ($input['status'] ?? $property['status']), ['draft', 'moderation', 'published', 'reserved', 'sold', 'archived'], (string) $property['status']),
                'source_type' => $this->allowed((string) ($input['source_type'] ?? $property['source_type']), ['own', 'partner', 'realtor', 'owner', 'developer'], (string) $property['source_type']),
                'location_id' => $this->existingLocationId($pdo, (int) ($input['location_id'] ?? 0), (int) $property['location_id']),
                'price_amount' => $this->decimalOrNull($input['price_amount'] ?? null),
                'price_currency' => $this->allowed((string) ($input['price_currency'] ?? $property['price_currency']), ['USD', 'EUR', 'UAH'], (string) $property['price_currency']),
                'price_period' => $this->allowed((string) ($input['price_period'] ?? $property['price_period']), ['total', 'month', 'day'], (string) $property['price_period']),
                'area_total' => $this->decimalOrNull($input['area_total'] ?? null),
                'area_living' => $this->decimalOrNull($input['area_living'] ?? null),
                'land_area' => $this->decimalOrNull($input['land_area'] ?? null),
                'rooms' => $this->decimalOrNull($input['rooms'] ?? null),
                'bedrooms' => $this->intOrNull($input['bedrooms'] ?? null, 0, 255),
                'bathrooms' => $this->intOrNull($input['bathrooms'] ?? null, 0, 255),
                'floor' => $this->intOrNull($input['floor'] ?? null, 0, 65535),
                'floors' => $this->intOrNull($input['floors'] ?? null, 0, 65535),
                'built_year' => $this->intOrNull($input['built_year'] ?? null, 1800, 2100),
                'address' => $this->nullable((string) ($input['address'] ?? ''), 255),
                'latitude' => $this->coordinateOrNull($input['latitude'] ?? null, -90, 90),
                'longitude' => $this->coordinateOrNull($input['longitude'] ?? null, -180, 180),
                'short_description' => $this->nullable((string) ($input['short_description'] ?? ''), 500),
                'description' => $this->nullableText((string) ($input['description'] ?? '')),
                'is_featured' => isset($input['is_featured']) ? 1 : 0,
                'has_3d_tour' => isset($input['has_3d_tour']) ? 1 : 0,
                'tour_url' => $this->nullable((string) ($input['tour_url'] ?? ''), 500),
                'video_url' => $this->nullable((string) ($input['video_url'] ?? ''), 500),
                'meta_title' => $this->nullable((string) ($input['meta_title'] ?? ''), 220),
                'meta_description' => $this->nullable((string) ($input['meta_description'] ?? ''), 500),
                'status_for_publish' => $this->allowed((string) ($input['status'] ?? $property['status']), ['draft', 'moderation', 'published', 'reserved', 'sold', 'archived'], (string) $property['status']),
            ];

            $statement = $pdo->prepare('
                UPDATE tn_properties
                SET slug = :slug,
                    title = :title,
                    deal_type = :deal_type,
                    type_id = :type_id,
                    status = :status,
                    source_type = :source_type,
                    location_id = :location_id,
                    price_amount = :price_amount,
                    price_currency = :price_currency,
                    price_period = :price_period,
                    area_total = :area_total,
                    area_living = :area_living,
                    land_area = :land_area,
                    rooms = :rooms,
                    bedrooms = :bedrooms,
                    bathrooms = :bathrooms,
                    floor = :floor,
                    floors = :floors,
                    built_year = :built_year,
                    address = :address,
                    latitude = :latitude,
                    longitude = :longitude,
                    short_description = :short_description,
                    description = :description,
                    is_featured = :is_featured,
                    has_3d_tour = :has_3d_tour,
                    tour_url = :tour_url,
                    video_url = :video_url,
                    meta_title = :meta_title,
                    meta_description = :meta_description,
                    published_at = CASE
                        WHEN :status_for_publish = "published" AND published_at IS NULL THEN NOW()
                        ELSE published_at
                    END,
                    updated_at = NOW()
                WHERE id = :id
                LIMIT 1
            ');
            $statement->execute($data);

            $pdo->commit();

            return ['ok' => true, 'message' => 'Об’єкт оновлено.'];
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }

            $this->logError('property-details-update', $e);

            return ['ok' => false, 'message' => 'Не вдалося оновити об’єкт. Деталі записано в лог.'];
        }
    }

    public function update(int $propertyId, array $input, array $files): array
    {
        $pdo = $this->database->connection();

        try {
            $pdo->beginTransaction();

            if (!$this->propertyForUpdate($pdo, $propertyId)) {
                $pdo->rollBack();

                return ['ok' => false, 'message' => 'Об’єкт не знайдено.'];
            }

            $this->deleteImages($pdo, $propertyId, (array) ($input['delete_images'] ?? []));
            $this->updateExistingImages($pdo, $propertyId, (array) ($input['images'] ?? []));

            $uploaded = $this->mediaStorage->storeUploadedFiles($files, 'property', $propertyId);
            $uploadedCoverId = $this->insertUploadedImages($pdo, $propertyId, $uploaded, (string) ($input['property_title'] ?? ''));

            $coverId = $uploadedCoverId ?: (int) ($input['cover_image_id'] ?? 0);
            $this->ensureCover($pdo, $propertyId, $coverId);

            $touch = $pdo->prepare('UPDATE tn_properties SET updated_at = NOW() WHERE id = :id LIMIT 1');
            $touch->execute(['id' => $propertyId]);

            $pdo->commit();

            return ['ok' => true, 'message' => 'Медіа об’єкта оновлено.'];
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }

            $this->logError('property-media-update', $e);

            return ['ok' => false, 'message' => 'Не вдалося оновити медіа. Деталі записано в лог.'];
        }
    }

    private function propertyForUpdate(PDO $pdo, int $propertyId): ?array
    {
        $statement = $pdo->prepare('SELECT * FROM tn_properties WHERE id = :id LIMIT 1 FOR UPDATE');
        $statement->execute(['id' => $propertyId]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);

        return $row ?: null;
    }

    private function existingTypeId(PDO $pdo, int $id, int $fallback): int
    {
        return $this->existingId($pdo, 'tn_property_types', $id, $fallback);
    }

    private function existingLocationId(PDO $pdo, int $id, int $fallback): int
    {
        return $this->existingId($pdo, 'tn_locations', $id, $fallback);
    }

    private function existingId(PDO $pdo, string $table, int $id, int $fallback): int
    {
        if ($id <= 0) {
            return $fallback;
        }

        $statement = $pdo->prepare('SELECT id FROM ' . $table . ' WHERE id = :id AND is_active = 1 LIMIT 1');
        $statement->execute(['id' => $id]);

        return $statement->fetchColumn() ? $id : $fallback;
    }

    private function uniqueSlug(PDO $pdo, string $slug, int $propertyId): string
    {
        $base = $this->limit($slug, 170);
        $candidate = $base;
        $counter = 2;

        $statement = $pdo->prepare('SELECT id FROM tn_properties WHERE slug = :slug AND id <> :id LIMIT 1');

        while (true) {
            $statement->execute(['slug' => $candidate, 'id' => $propertyId]);
            if (!$statement->fetchColumn()) {
                return $candidate;
            }

            $suffix = '-' . $counter++;
            $candidate = mb_substr($base, 0, 180 - mb_strlen($suffix)) . $suffix;
        }
    }

    private function slugValue(string $value): string
    {
        $value = mb_strtolower(trim($value));
        $map = [
            'а' => 'a', 'б' => 'b', 'в' => 'v', 'г' => 'h', 'ґ' => 'g', 'д' => 'd',
            'е' => 'e', 'є' => 'ie', 'ж' => 'zh', 'з' => 'z', 'и' => 'y', 'і' => 'i',
            'ї' => 'i', 'й' => 'i', 'к' => 'k', 'л' => 'l', 'м' => 'm', 'н' => 'n',
            'о' => 'o', 'п' => 'p', 'р' => 'r', 'с' => 's', 'т' => 't', 'у' => 'u',
            'ф' => 'f', 'х' => 'kh', 'ц' => 'ts', 'ч' => 'ch', 'ш' => 'sh', 'щ' => 'shch',
            'ь' => '', 'ю' => 'iu', 'я' => 'ia',
        ];
        $value = strtr($value, $map);
        $value = preg_replace('~[^a-z0-9]+~', '-', $value) ?: '';

        return trim($value, '-');
    }

    private function allowed(string $value, array $allowed, string $fallback): string
    {
        return in_array($value, $allowed, true) ? $value : $fallback;
    }

    private function decimalOrNull(mixed $value): ?string
    {
        if ($value === null || trim((string) $value) === '') {
            return null;
        }

        $normalized = str_replace(',', '.', trim((string) $value));

        return is_numeric($normalized) ? number_format((float) $normalized, 2, '.', '') : null;
    }

    private function coordinateOrNull(mixed $value, float $min, float $max): ?string
    {
        if ($value === null || trim((string) $value) === '') {
            return null;
        }

        $normalized = str_replace(',', '.', trim((string) $value));
        if (!is_numeric($normalized)) {
            return null;
        }

        $number = (float) $normalized;
        if ($number < $min || $number > $max) {
            return null;
        }

        return number_format($number, 7, '.', '');
    }

    private function intOrNull(mixed $value, int $min, int $max): ?int
    {
        if ($value === null || trim((string) $value) === '') {
            return null;
        }

        $number = (int) $value;
        if ($number < $min || $number > $max) {
            return null;
        }

        return $number;
    }

    private function deleteImages(PDO $pdo, int $propertyId, array $ids): void
    {
        $ids = $this->positiveIds($ids);

        if (!$ids) {
            return;
        }

        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $statement = $pdo->prepare('DELETE FROM tn_property_images WHERE property_id = ? AND id IN (' . $placeholders . ')');
        $statement->execute(array_merge([$propertyId], $ids));
    }

    private function updateExistingImages(PDO $pdo, int $propertyId, array $images): void
    {
        $statement = $pdo->prepare('
            UPDATE tn_property_images
            SET alt_text = :alt_text, sort_order = :sort_order
            WHERE property_id = :property_id AND id = :id
            LIMIT 1
        ');

        foreach ($images as $id => $image) {
            $imageId = (int) $id;

            if ($imageId <= 0 || !is_array($image)) {
                continue;
            }

            $statement->execute([
                'id' => $imageId,
                'property_id' => $propertyId,
                'alt_text' => $this->nullable((string) ($image['alt_text'] ?? ''), 220),
                'sort_order' => max(0, (int) ($image['sort_order'] ?? 100)),
            ]);
        }
    }

    private function insertUploadedImages(PDO $pdo, int $propertyId, array $uploaded, string $title): ?int
    {
        if (!$uploaded) {
            return null;
        }

        $coverId = null;
        $sortOrder = $this->nextSortOrder($pdo, $propertyId);
        $statement = $pdo->prepare('
            INSERT INTO tn_property_images (property_id, image_url, alt_text, sort_order, is_cover)
            VALUES (:property_id, :image_url, :alt_text, :sort_order, :is_cover)
        ');

        foreach ($uploaded as $asset) {
            $role = (string) ($asset['role'] ?? 'gallery');
            $isCover = $role === 'cover' ? 1 : 0;
            $sort = $isCover ? 5 : $sortOrder;

            $statement->execute([
                'property_id' => $propertyId,
                'image_url' => $this->limit((string) $asset['public_url'], 700),
                'alt_text' => $this->limit($title, 220),
                'sort_order' => $sort,
                'is_cover' => $isCover,
            ]);

            $imageId = (int) $pdo->lastInsertId();
            if ($isCover && $imageId > 0) {
                $coverId = $imageId;
            }

            $sortOrder += 10;
        }

        return $coverId;
    }

    private function ensureCover(PDO $pdo, int $propertyId, int $coverId): void
    {
        $pdo->prepare('UPDATE tn_property_images SET is_cover = 0 WHERE property_id = :property_id')
            ->execute(['property_id' => $propertyId]);

        if ($coverId > 0) {
            $statement = $pdo->prepare('
                UPDATE tn_property_images
                SET is_cover = 1, sort_order = LEAST(sort_order, 10)
                WHERE property_id = :property_id AND id = :id
                LIMIT 1
            ');
            $statement->execute(['property_id' => $propertyId, 'id' => $coverId]);
        }

        $statement = $pdo->prepare('SELECT COUNT(*) FROM tn_property_images WHERE property_id = :property_id AND is_cover = 1');
        $statement->execute(['property_id' => $propertyId]);

        if ((int) $statement->fetchColumn() > 0) {
            return;
        }

        $fallback = $pdo->prepare('
            UPDATE tn_property_images
            SET is_cover = 1
            WHERE property_id = :property_id
            ORDER BY sort_order, id
            LIMIT 1
        ');
        $fallback->execute(['property_id' => $propertyId]);
    }

    private function nextSortOrder(PDO $pdo, int $propertyId): int
    {
        $statement = $pdo->prepare('SELECT COALESCE(MAX(sort_order), 0) + 10 FROM tn_property_images WHERE property_id = :property_id');
        $statement->execute(['property_id' => $propertyId]);

        return max(10, (int) $statement->fetchColumn());
    }

    private function positiveIds(array $values): array
    {
        $ids = [];
        foreach ($values as $value) {
            $id = (int) $value;
            if ($id > 0) {
                $ids[] = $id;
            }
        }

        return array_values(array_unique($ids));
    }

    private function nullable(string $value, int $limit): ?string
    {
        $value = $this->limit($value, $limit);

        return $value === '' ? null : $value;
    }

    private function nullableText(string $value): ?string
    {
        $value = trim($value);

        return $value === '' ? null : $value;
    }

    private function limit(string $value, int $limit): string
    {
        return mb_substr(trim($value), 0, $limit);
    }

    private function logError(string $label, Throwable $error): void
    {
        $directory = BASE_PATH . '/tmp/logs';

        if (!is_dir($directory)) {
            @mkdir($directory, 0775, true);
        }

        $entry = sprintf("[%s] %s: %s%s", date('Y-m-d H:i:s'), $label, $error->getMessage(), PHP_EOL);
        @file_put_contents($directory . '/frontend.log', $entry, FILE_APPEND);
    }
}
