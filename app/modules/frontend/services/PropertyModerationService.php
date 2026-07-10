<?php
declare(strict_types=1);

namespace Modules\Frontend\Services;

use Common\Services\DatabaseService;
use Common\Services\MediaStorageService;
use PDO;
use Throwable;

class PropertyModerationService
{
    public function __construct(private DatabaseService $database, private MediaStorageService $mediaStorage)
    {
    }

    public function submissions(string $status = ''): array
    {
        $where = '';
        $params = [];

        if ($status !== '' && in_array($status, ['new', 'review', 'accepted', 'rejected', 'spam'], true)) {
            $where = 'WHERE s.status = :status';
            $params['status'] = $status;
        }

        return $this->database->fetchAll('
            SELECT s.*
            FROM tn_property_submissions s
            ' . $where . '
            ORDER BY
                FIELD(s.status, "new", "review", "accepted", "rejected", "spam"),
                s.created_at DESC,
                s.id DESC
            LIMIT 100
        ', $params);
    }

    public function submission(int $id): ?array
    {
        return $this->database->fetchOne('
            SELECT s.*, p.public_id, p.slug AS property_slug, p.status AS property_status
            FROM tn_property_submissions s
            LEFT JOIN tn_properties p ON p.id = s.property_id
            WHERE s.id = :id
            LIMIT 1
        ', ['id' => $id]);
    }

    public function counts(): array
    {
        $rows = $this->database->fetchAll('
            SELECT status, COUNT(*) AS total
            FROM tn_property_submissions
            GROUP BY status
        ');

        $counts = ['new' => 0, 'review' => 0, 'accepted' => 0, 'rejected' => 0, 'spam' => 0];
        foreach ($rows as $row) {
            $counts[$row['status']] = (int) $row['total'];
        }

        return $counts;
    }

    public function submissionMedia(int $id): array
    {
        return $this->mediaStorage->assetsFor('property_submission', $id);
    }

    public function moderate(int $id, string $action, string $note = ''): array
    {
        return match ($action) {
            'review' => $this->setStatus($id, 'review', $note),
            'reject' => $this->setStatus($id, 'rejected', $note),
            'spam' => $this->setStatus($id, 'spam', $note),
            'publish' => $this->publish($id, $note),
            default => ['ok' => false, 'message' => 'Невідома дія модерації.'],
        };
    }

    private function setStatus(int $id, string $status, string $note): array
    {
        $statement = $this->database->connection()->prepare('
            UPDATE tn_property_submissions
            SET status = :status, reviewed_at = NOW(), review_note = :note
            WHERE id = :id
            LIMIT 1
        ');
        $statement->execute([
            'id' => $id,
            'status' => $status,
            'note' => $this->nullable($note, 500),
        ]);

        return ['ok' => $statement->rowCount() > 0, 'message' => 'Статус заявки оновлено.'];
    }

    private function publish(int $id, string $note): array
    {
        $pdo = $this->database->connection();

        try {
            $pdo->beginTransaction();

            $submission = $this->submissionForUpdate($pdo, $id);
            if (!$submission) {
                $pdo->rollBack();

                return ['ok' => false, 'message' => 'Заявку не знайдено.'];
            }

            if (!empty($submission['property_id'])) {
                $pdo->commit();

                return ['ok' => true, 'message' => 'Заявка вже пов’язана з об’єктом.'];
            }

            $typeId = $this->propertyTypeId($pdo, (string) $submission['property_type']);
            $locationId = $this->locationId($pdo, $submission);
            $agentId = $this->defaultAgentId($pdo);
            $publicId = $this->nextPublicId($pdo);
            $slug = $this->uniqueSlug($pdo, (string) $submission['title']);
            $mediaLinks = $this->extractUrls((string) ($submission['media_links'] ?? ''));
            $coverUrl = $mediaLinks[0] ?? null;
            $uploadedMedia = $this->mediaStorage->assetsFor('property_submission', $id);
            $uploadedCover = $this->coverMedia($uploadedMedia);

            if ($uploadedCover) {
                $coverUrl = $uploadedCover['public_url'];
            }

            $statement = $pdo->prepare('
                INSERT INTO tn_properties (
                    public_id, slug, title, deal_type, type_id, status, source_type, location_id, agent_id,
                    price_amount, price_currency, price_period, area_total, land_area, rooms, floor, floors, built_year,
                    address, short_description, description, features_json, is_featured, has_3d_tour, tour_url,
                    meta_title, meta_description, published_at
                ) VALUES (
                    :public_id, :slug, :title, :deal_type, :type_id, "published", :source_type, :location_id, :agent_id,
                    :price_amount, :price_currency, "total", :area_total, :land_area, :rooms, :floor, :floors, :built_year,
                    :address, :short_description, :description, :features_json, 0, :has_3d_tour, :tour_url,
                    :meta_title, :meta_description, NOW()
                )
            ');
            $statement->execute([
                'public_id' => $publicId,
                'slug' => $slug,
                'title' => $this->limit((string) $submission['title'], 220),
                'deal_type' => $this->dealType((string) $submission['deal_type']),
                'type_id' => $typeId,
                'source_type' => $this->propertySource((string) $submission['source_type']),
                'location_id' => $locationId,
                'agent_id' => $agentId,
                'price_amount' => $this->decimal($submission['price_amount']),
                'price_currency' => $this->currency((string) $submission['price_currency']),
                'area_total' => $this->decimal($submission['area_total']),
                'land_area' => $this->decimal($submission['land_area']),
                'rooms' => $this->decimal($submission['rooms']),
                'floor' => $this->positiveInt($submission['floor']),
                'floors' => $this->positiveInt($submission['floors']),
                'built_year' => $this->positiveInt($submission['built_year']),
                'address' => $this->nullable((string) ($submission['address'] ?? ''), 255),
                'short_description' => $this->limit((string) $submission['description'], 500),
                'description' => $this->limit((string) $submission['description'], 5000),
                'features_json' => json_encode([
                    'submission_ref' => $submission['submission_ref'],
                    'features_text' => $submission['features_text'],
                    'owner_contact' => [
                        'name' => $submission['owner_name'],
                        'phone' => $submission['owner_phone'],
                        'email' => $submission['owner_email'],
                    ],
                ], JSON_UNESCAPED_UNICODE),
                'has_3d_tour' => (int) $submission['has_3d_tour'],
                'tour_url' => (int) $submission['has_3d_tour'] === 1 ? $coverUrl : null,
                'meta_title' => $this->limit((string) $submission['title'] . ' | Terra Nova CLUB', 220),
                'meta_description' => $this->limit((string) $submission['description'], 500),
            ]);

            $propertyId = (int) $pdo->lastInsertId();

            if ($coverUrl) {
                $this->insertImage($pdo, $propertyId, $coverUrl, (string) $submission['title']);
            }

            $mediaSort = 20;
            foreach ($uploadedMedia as $asset) {
                $role = (string) ($asset['role'] ?? 'gallery');
                $sortOrder = (int) ($asset['sort_order'] ?? $mediaSort);
                $this->mediaStorage->relateExistingMedia((int) $asset['id'], 'property', $propertyId, $role, $sortOrder);

                if (($asset['kind'] ?? '') === 'image' && (string) $asset['public_url'] !== (string) $coverUrl) {
                    $this->insertImage($pdo, $propertyId, (string) $asset['public_url'], (string) $submission['title'], $sortOrder);
                }

                $mediaSort += 10;
            }

            if (trim((string) ($submission['features_text'] ?? '')) !== '') {
                $this->insertFeature($pdo, $propertyId, 'submission', (string) $submission['features_text']);
            }

            $update = $pdo->prepare('
                UPDATE tn_property_submissions
                SET status = "accepted", property_id = :property_id, reviewed_at = NOW(), review_note = :note
                WHERE id = :id
                LIMIT 1
            ');
            $update->execute([
                'id' => $id,
                'property_id' => $propertyId,
                'note' => $this->nullable($note, 500),
            ]);

            $pdo->commit();

            return ['ok' => true, 'message' => 'Заявку опубліковано в каталозі.', 'property_id' => $propertyId, 'slug' => $slug];
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }

            $this->logError('property-publish', $e);

            return ['ok' => false, 'message' => 'Не вдалося опублікувати об’єкт. Деталі записано в лог.'];
        }
    }

    private function submissionForUpdate(PDO $pdo, int $id): ?array
    {
        $statement = $pdo->prepare('SELECT * FROM tn_property_submissions WHERE id = :id LIMIT 1 FOR UPDATE');
        $statement->execute(['id' => $id]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);

        return $row ?: null;
    }

    private function propertyTypeId(PDO $pdo, string $code): int
    {
        $statement = $pdo->prepare('SELECT id FROM tn_property_types WHERE code = :code LIMIT 1');
        $statement->execute(['code' => $code]);
        $id = $statement->fetchColumn();

        if ($id) {
            return (int) $id;
        }

        return (int) $pdo->query('SELECT id FROM tn_property_types ORDER BY sort_order, id LIMIT 1')->fetchColumn();
    }

    private function locationId(PDO $pdo, array $submission): int
    {
        $city = $this->limit((string) $submission['city'], 120);
        $slug = $this->slug($city);
        $statement = $pdo->prepare('SELECT id FROM tn_locations WHERE slug = :slug LIMIT 1');
        $statement->execute(['slug' => $slug]);
        $id = $statement->fetchColumn();

        if ($id) {
            return (int) $id;
        }

        $insert = $pdo->prepare('
            INSERT INTO tn_locations (country_code, region, city, district, slug, is_active)
            VALUES ("UA", :region, :city, :district, :slug, 1)
        ');
        $insert->execute([
            'region' => $this->nullable((string) ($submission['region'] ?? ''), 120),
            'city' => $city,
            'district' => $this->nullable((string) ($submission['district'] ?? ''), 120),
            'slug' => $slug,
        ]);

        return (int) $pdo->lastInsertId();
    }

    private function defaultAgentId(PDO $pdo): ?int
    {
        $id = $pdo->query('SELECT id FROM tn_agents WHERE is_active = 1 ORDER BY id LIMIT 1')->fetchColumn();

        return $id ? (int) $id : null;
    }

    private function nextPublicId(PDO $pdo): string
    {
        $number = (int) $pdo->query("SELECT COALESCE(MAX(CAST(SUBSTRING(public_id, 4) AS UNSIGNED)), 0) + 1 FROM tn_properties WHERE public_id LIKE 'TN-%'")->fetchColumn();

        return 'TN-' . str_pad((string) $number, 4, '0', STR_PAD_LEFT);
    }

    private function uniqueSlug(PDO $pdo, string $title): string
    {
        $base = $this->slug($title) ?: 'property';
        $slug = $base;
        $index = 2;

        $statement = $pdo->prepare('SELECT COUNT(*) FROM tn_properties WHERE slug = :slug');
        while (true) {
            $statement->execute(['slug' => $slug]);
            if ((int) $statement->fetchColumn() === 0) {
                return $slug;
            }

            $slug = $base . '-' . $index;
            $index++;
        }
    }

    private function coverMedia(array $media): ?array
    {
        foreach ($media as $asset) {
            if (($asset['role'] ?? '') === 'cover' && ($asset['kind'] ?? '') === 'image') {
                return $asset;
            }
        }

        foreach ($media as $asset) {
            if (($asset['kind'] ?? '') === 'image') {
                return $asset;
            }
        }

        return null;
    }

    private function insertImage(PDO $pdo, int $propertyId, string $url, string $title, int $sortOrder = 10): void
    {
        $statement = $pdo->prepare('
            INSERT IGNORE INTO tn_property_images (property_id, image_url, alt_text, sort_order, is_cover)
            VALUES (:property_id, :image_url, :alt_text, :sort_order, :is_cover)
        ');
        $statement->execute([
            'property_id' => $propertyId,
            'image_url' => $this->limit($url, 700),
            'alt_text' => $this->limit($title, 220),
            'sort_order' => $sortOrder,
            'is_cover' => $sortOrder === 10 ? 1 : 0,
        ]);
    }

    private function insertFeature(PDO $pdo, int $propertyId, string $key, string $value): void
    {
        $statement = $pdo->prepare('
            INSERT IGNORE INTO tn_property_features (property_id, feature_key, feature_value, sort_order)
            VALUES (:property_id, :feature_key, :feature_value, 10)
        ');
        $statement->execute([
            'property_id' => $propertyId,
            'feature_key' => $this->limit($key, 80),
            'feature_value' => $this->limit($value, 255),
        ]);
    }

    private function extractUrls(string $text): array
    {
        preg_match_all('~https?://[^\s,]+~i', $text, $matches);

        return array_values(array_unique($matches[0] ?? []));
    }

    private function slug(string $value): string
    {
        $value = mb_strtolower(trim($value));
        $map = [
            'а' => 'a', 'б' => 'b', 'в' => 'v', 'г' => 'h', 'ґ' => 'g', 'д' => 'd', 'е' => 'e', 'є' => 'ye',
            'ж' => 'zh', 'з' => 'z', 'и' => 'y', 'і' => 'i', 'ї' => 'yi', 'й' => 'y', 'к' => 'k', 'л' => 'l',
            'м' => 'm', 'н' => 'n', 'о' => 'o', 'п' => 'p', 'р' => 'r', 'с' => 's', 'т' => 't', 'у' => 'u',
            'ф' => 'f', 'х' => 'kh', 'ц' => 'ts', 'ч' => 'ch', 'ш' => 'sh', 'щ' => 'shch', 'ь' => '',
            'ю' => 'yu', 'я' => 'ya',
        ];
        $value = strtr($value, $map);
        $value = preg_replace('~[^a-z0-9]+~', '-', $value) ?: '';

        return trim($value, '-');
    }

    private function limit(string $value, int $limit): string
    {
        return mb_substr(trim($value), 0, $limit);
    }

    private function nullable(string $value, int $limit): ?string
    {
        $value = $this->limit($value, $limit);

        return $value === '' ? null : $value;
    }

    private function decimal(mixed $value): ?float
    {
        return is_numeric($value) ? max(0, (float) $value) : null;
    }

    private function positiveInt(mixed $value): ?int
    {
        return is_numeric($value) && (int) $value > 0 ? (int) $value : null;
    }

    private function dealType(string $value): string
    {
        return in_array($value, ['sale', 'rent', 'investment'], true) ? $value : 'sale';
    }

    private function propertySource(string $value): string
    {
        return in_array($value, ['partner', 'realtor', 'owner', 'developer'], true) ? $value : 'owner';
    }

    private function currency(string $value): string
    {
        $value = strtoupper(trim($value));

        return in_array($value, ['USD', 'EUR', 'UAH'], true) ? $value : 'USD';
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
