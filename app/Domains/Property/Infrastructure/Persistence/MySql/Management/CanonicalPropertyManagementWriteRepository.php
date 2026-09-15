<?php
declare(strict_types=1);

namespace Domains\Property\Infrastructure\Persistence\MySql\Management;

use Domains\Property\Application\Contract\PropertyCompatibilityProjectionInterface;
use Domains\Property\Application\Contract\PropertyManagementWriteRepositoryInterface;
use Domains\Property\Application\Service\PropertyCanonicalRuntimeService;
use Domains\Property\Infrastructure\Persistence\MySql\MysqlPropertyManagementRepository;
use PDO;
use Throwable;

final readonly class CanonicalPropertyManagementWriteRepository implements PropertyManagementWriteRepositoryInterface
{
    public function __construct(
        private PropertyCanonicalRuntimeService $runtime,
        private MysqlPropertyManagementRepository $legacyOperations,
        private PropertyCompatibilityProjectionInterface $compatibility,
        private PDO $connection,
        private string $organizationId,
    ) {}

    public function createDraft(array $input, ?int $userId = null, array $files = []): array
    {
        try {
            $normalized = $this->canonicalInput($input, true);
            $title = trim((string) ($input['title'] ?? ''));
            if ($title === '') return ['ok' => false, 'message' => 'Назва об’єкта обов’язкова.'];

            $bundle = $this->runtime->createBundle(
                $this->organizationId,
                $normalized + ['lifecycle' => (string) ($input['lifecycle'] ?? 'unknown')],
                [
                    'transaction_type' => (string) ($input['deal_type'] ?? 'sale'),
                    'status' => 'off_market',
                    'price_amount' => $input['price_amount'] ?? null,
                    'price_currency' => (string) ($input['price_currency'] ?? 'USD'),
                    'price_period' => (string) ($input['price_period'] ?? 'total'),
                    'responsible_party_reference' => !empty($input['agent_id']) ? 'LEGACY:agent:' . (int) $input['agent_id'] : null,
                ],
                [
                    'status' => 'draft',
                    'title' => $title,
                    'description' => (string) ($input['description'] ?? $input['short_description'] ?? ''),
                    'presentation_price_amount' => $input['price_amount'] ?? null,
                    'presentation_price_currency' => (string) ($input['price_currency'] ?? 'USD'),
                    'slug' => (string) ($input['slug'] ?? ''),
                    'visibility' => (string) ($input['visibility'] ?? 'public'),
                    'seo_title' => $input['meta_title'] ?? null,
                    'seo_description' => $input['meta_description'] ?? null,
                    'public_features' => $this->publicFeatures($input),
                ],
                $this->actor($userId),
            );

            $legacyId = (int) ($bundle['legacy_property_id'] ?? 0);
            if ($legacyId <= 0) throw new \RuntimeException('Canonical Property projection did not return a compatibility id.');
            $this->compatibility->syncOperationalMetadata($this->organizationId, $legacyId, $input);
            $this->compatibility->recordActivity($this->organizationId, $legacyId, $userId, 'system', 'Чернетку об’єкта створено', 'Створено через canonical Property runtime.');
            if ($files !== []) {
                $media = $this->legacyOperations->update($legacyId, ['property_title' => $title], $files, $userId);
                if (empty($media['ok'])) return $media;
            }

            return [
                'ok' => true,
                'message' => 'Чернетку об’єкта створено через canonical Property runtime.',
                'property_id' => $legacyId,
                'asset_id' => $bundle['asset_id'] ?? null,
                'inventory_id' => $bundle['inventory_id'] ?? null,
                'listing_id' => $bundle['listing_id'] ?? null,
            ];
        } catch (Throwable $e) {
            return ['ok' => false, 'message' => 'Не вдалося створити canonical Property: ' . $e->getMessage()];
        }
    }

    public function updateStatus(int $propertyId, string $status, string $note = '', ?int $userId = null): array
    {
        try {
            $this->runtime->applyLegacyStatus($this->organizationId, $propertyId, $status, $note, $this->actor($userId));
            $metadata = trim($note) !== '' ? ['status_note' => mb_substr($note, 0, 500)] : [];
            $this->compatibility->syncOperationalMetadata($this->organizationId, $propertyId, $metadata);
            $this->compatibility->recordActivity(
                $this->organizationId,
                $propertyId,
                $userId,
                'status_change',
                'Комерційний стан оновлено',
                trim($note) !== '' ? $note : 'Стан синхронізовано з canonical Property runtime.',
            );
            return ['ok' => true, 'message' => 'Комерційний стан об’єкта оновлено.'];
        } catch (Throwable $e) {
            return ['ok' => false, 'message' => 'Не вдалося змінити стан об’єкта: ' . $e->getMessage()];
        }
    }

    public function updateDetails(int $propertyId, array $input, ?int $userId = null): array
    {
        try {
            $canonical = $this->canonicalInput($input, false);
            $this->runtime->patchBundleByLegacyId($this->organizationId, $propertyId, $canonical + $input, $this->actor($userId));
            $this->compatibility->syncOperationalMetadata($this->organizationId, $propertyId, $input);
            if (isset($input['status']) && trim((string) $input['status']) !== '') {
                $this->runtime->applyLegacyStatus(
                    $this->organizationId,
                    $propertyId,
                    (string) $input['status'],
                    isset($input['status_note']) ? (string) $input['status_note'] : null,
                    $this->actor($userId),
                );
            }
            $this->compatibility->recordActivity($this->organizationId, $propertyId, $userId, 'details_update', 'Картку об’єкта оновлено', 'Canonical Property state synchronized.');
            return ['ok' => true, 'message' => 'Об’єкт оновлено через canonical Property runtime.'];
        } catch (Throwable $e) {
            return ['ok' => false, 'message' => 'Не вдалося оновити об’єкт: ' . $e->getMessage()];
        }
    }

    public function update(int $propertyId, array $input, array $files, ?int $userId = null): array
    {
        // Media storage is still a compatibility surface in V0.12. It does not own Asset,
        // Inventory or Listing state and may only touch legacy media bookkeeping.
        return $this->legacyOperations->update($propertyId, $input, $files, $userId);
    }

    public function addActivityNote(int $propertyId, array $input, ?int $userId = null): array
    {
        $title = trim((string) ($input['activity_title'] ?? ''));
        $body = trim((string) ($input['activity_body'] ?? ''));
        if ($title === '') return ['ok' => false, 'message' => 'Вкажіть коротку назву нотатки.'];
        if ($body === '') return ['ok' => false, 'message' => 'Додайте текст нотатки.'];
        try {
            $this->compatibility->recordActivity($this->organizationId, $propertyId, $userId, 'note', $title, $body);
            return ['ok' => true, 'message' => 'Нотатку додано в журнал об’єкта.'];
        } catch (Throwable $e) {
            return ['ok' => false, 'message' => 'Не вдалося додати нотатку: ' . $e->getMessage()];
        }
    }

    private function canonicalInput(array $input, bool $requireLocation): array
    {
        $result = [];
        if (!empty($input['type_id'])) {
            $type = $this->fetchOne('SELECT id,code FROM tn_property_types WHERE id=:id LIMIT 1', ['id' => (int) $input['type_id']]);
            if ($type !== null) {
                $result['type_code'] = (string) $type['code'];
                $result['type_reference_id'] = (int) $type['id'];
            }
        }
        if ($requireLocation || !empty($input['location_id'])) {
            $location = $this->fetchOne('SELECT id,country_code,region,city,district,latitude,longitude FROM tn_locations WHERE id=:id LIMIT 1', ['id' => (int) ($input['location_id'] ?? 0)]);
            if ($location === null) throw new \InvalidArgumentException('Оберіть коректну локацію.');
            $result += [
                'country_code' => (string) ($location['country_code'] ?: 'UA'),
                'region' => (string) ($location['region'] ?? ''),
                'city' => (string) ($location['city'] ?? ''),
                'district' => $location['district'] ?? null,
                'latitude' => $input['latitude'] ?? $location['latitude'] ?? null,
                'longitude' => $input['longitude'] ?? $location['longitude'] ?? null,
            ];
        }
        foreach (['address','area_total','area_living','land_area','rooms','bedrooms','bathrooms','floor','floors','built_year','latitude','longitude','kind','lifecycle'] as $field) {
            if (array_key_exists($field, $input)) $result[$field] = $input[$field];
        }
        if (!isset($result['type_code']) && $requireLocation) throw new \InvalidArgumentException('Оберіть коректний тип об’єкта.');
        return $result;
    }

    private function publicFeatures(array $input): array
    {
        $features = [];
        if (array_key_exists('is_featured', $input)) $features['is_featured'] = filter_var($input['is_featured'], FILTER_VALIDATE_BOOL) || $input['is_featured'] === '1';
        if (!empty($input['tour_url'])) $features['tour_url'] = (string) $input['tour_url'];
        if (!empty($input['video_url'])) $features['video_url'] = (string) $input['video_url'];
        return $features;
    }

    private function fetchOne(string $sql, array $params): ?array
    {
        $statement = $this->connection->prepare($sql);
        $statement->execute($params);
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        return $row === false ? null : $row;
    }

    private function actor(?int $userId): ?string
    {
        return $userId !== null && $userId > 0 ? 'user:' . $userId : null;
    }
}
