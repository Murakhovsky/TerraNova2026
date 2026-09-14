<?php
declare(strict_types=1);

namespace Domains\Property\Infrastructure\Persistence\MySql\Management;

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
            $this->updateOperationalMetadata($legacyId, $input);
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
            $this->recordStatusNote($propertyId, $note, $userId);
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
            $this->updateOperationalMetadata($propertyId, $input);
            if (isset($input['status']) && trim((string) $input['status']) !== '') {
                $this->runtime->applyLegacyStatus(
                    $this->organizationId,
                    $propertyId,
                    (string) $input['status'],
                    isset($input['status_note']) ? (string) $input['status_note'] : null,
                    $this->actor($userId),
                );
            }
            return ['ok' => true, 'message' => 'Об’єкт оновлено через canonical Property runtime.'];
        } catch (Throwable $e) {
            return ['ok' => false, 'message' => 'Не вдалося оновити об’єкт: ' . $e->getMessage()];
        }
    }

    public function update(int $propertyId, array $input, array $files, ?int $userId = null): array
    {
        return $this->legacyOperations->update($propertyId, $input, $files, $userId);
    }

    public function addActivityNote(int $propertyId, array $input, ?int $userId = null): array
    {
        return $this->legacyOperations->addActivityNote($propertyId, $input, $userId);
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

    private function updateOperationalMetadata(int $propertyId, array $input): void
    {
        $allowed = [
            'property_group_id','commission_type','commission_value','sale_priority','min_price_amount',
            'reserved_until','reserved_by_case_id','fixed_client_case_id','manager_note','source_note',
            'status_note','operational_stage','next_action_title','next_action_due_at','next_action_note',
        ];
        $set = [];
        $params = ['id' => $propertyId, 'organization_id' => $this->organizationId];
        foreach ($allowed as $field) {
            if (!array_key_exists($field, $input)) continue;
            $set[] = $field . '=:' . $field;
            $value = $input[$field];
            $params[$field] = ($value === '' || $value === 0 || $value === '0') && in_array($field, ['property_group_id','reserved_by_case_id','fixed_client_case_id'], true) ? null : $value;
        }
        if ($set === []) return;
        $set[] = 'updated_at=NOW()';
        $statement = $this->connection->prepare('UPDATE tn_properties SET ' . implode(',', $set) . ' WHERE id=:id AND organization_id=:organization_id LIMIT 1');
        $statement->execute($params);
    }

    private function recordStatusNote(int $propertyId, string $note, ?int $userId): void
    {
        if (trim($note) !== '') {
            $this->connection->prepare('UPDATE tn_properties SET status_note=:note,status_changed_at=NOW() WHERE id=:id AND organization_id=:organization_id LIMIT 1')
                ->execute(['note' => mb_substr($note, 0, 500), 'id' => $propertyId, 'organization_id' => $this->organizationId]);
        }
        $this->legacyOperations->addActivityNote($propertyId, [
            'activity_title' => 'Комерційний стан оновлено',
            'activity_body' => trim($note) !== '' ? $note : 'Стан синхронізовано з canonical Property runtime.',
        ], $userId);
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
