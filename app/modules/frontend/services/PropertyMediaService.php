<?php
declare(strict_types=1);

namespace Modules\Frontend\Services;

use Common\Services\DatabaseService;
use Common\Services\MediaStorageService;
use PDO;
use Throwable;

class PropertyMediaService
{
    private const OPERATIONAL_STAGE_RULES = [
        'intake' => [
            'label' => 'Первинне внесення',
            'description' => 'Створити базову картку обʼєкта: назва, тип, локація, джерело.',
            'requires_next_action' => false,
        ],
        'verification' => [
            'label' => 'Перевірка даних',
            'description' => 'Призначити відповідального і перевірити адресу, джерело та базові параметри.',
            'requires_next_action' => true,
        ],
        'media_needed' => [
            'label' => 'Потрібні медіа',
            'description' => 'Підготувати фото, головне зображення, 3D або відео, якщо вони потрібні.',
            'requires_next_action' => true,
        ],
        'pricing' => [
            'label' => 'Узгодження ціни',
            'description' => 'Зафіксувати ціну або пояснення, що ціна надається за запитом.',
            'requires_next_action' => true,
        ],
        'ready_to_publish' => [
            'label' => 'Готовий до публікації',
            'description' => 'Картка пройшла чекліст готовності й може бути опублікована.',
            'requires_next_action' => true,
        ],
        'published' => [
            'label' => 'В роботі на ринку',
            'description' => 'Обʼєкт опублікований і менеджер веде заявки, покази та підбори.',
            'requires_next_action' => true,
        ],
        'negotiation' => [
            'label' => 'Переговори',
            'description' => 'Є предметні перемовини з клієнтом або стороною власника.',
            'requires_next_action' => true,
        ],
        'reserved' => [
            'label' => 'Резерв',
            'description' => 'Обʼєкт зарезервовано, причина або контекст має бути в статусі.',
            'requires_next_action' => true,
        ],
        'deal' => [
            'label' => 'Угода',
            'description' => 'Угоду зафіксовано, обʼєкт має статус продано.',
            'requires_next_action' => false,
        ],
        'aftercare' => [
            'label' => 'Післяугодний супровід',
            'description' => 'Після угоди ведеться супровід документів, сервісу або наступних потреб клієнта.',
            'requires_next_action' => true,
        ],
        'archived' => [
            'label' => 'Архів',
            'description' => 'Роботу з обʼєктом завершено або призупинено з причиною.',
            'requires_next_action' => false,
        ],
    ];

    private const OPERATIONAL_STAGE_ORDER = [
        'intake',
        'verification',
        'media_needed',
        'pricing',
        'ready_to_publish',
        'published',
        'negotiation',
        'reserved',
        'deal',
        'aftercare',
        'archived',
    ];

    public function __construct(private DatabaseService $database, private MediaStorageService $mediaStorage)
    {
    }

    public function property(int $id): ?array
    {
        return $this->database->fetchOne('
            SELECT p.*,
                   t.name_uk AS type_name,
                   l.city, l.region,
                   g.title AS group_title, g.slug AS group_slug, g.group_type, g.address AS group_address,
                   a.public_name AS agent_name
            FROM tn_properties p
            INNER JOIN tn_property_types t ON t.id = p.type_id
            INNER JOIN tn_locations l ON l.id = p.location_id
            LEFT JOIN tn_property_groups g ON g.id = p.property_group_id
            LEFT JOIN tn_agents a ON a.id = p.agent_id
            WHERE p.id = :id
            LIMIT 1
        ', ['id' => $id]);
    }

    public function images(int $propertyId): array
    {
        $this->syncMediaAssetsToPropertyImages($propertyId);

        return $this->database->fetchAll('
            SELECT id, image_url, alt_text, sort_order, is_cover, created_at
            FROM tn_property_images
            WHERE property_id = :property_id
            ORDER BY is_cover DESC, sort_order, id
        ', ['property_id' => $propertyId]);
    }

    private function syncMediaAssetsToPropertyImages(int $propertyId): void
    {
        if ($propertyId <= 0) {
            return;
        }

        $pdo = $this->database->connection();
        $media = $pdo->prepare('
            SELECT a.public_url, a.original_name, r.role, r.sort_order, a.created_at
            FROM tn_media_relations r
            INNER JOIN tn_media_assets a ON a.id = r.media_id
            WHERE r.entity_type = "property"
              AND r.entity_id = :property_id
              AND a.kind = "image"
              AND a.status <> "deleted"
              AND NOT EXISTS (
                  SELECT 1
                  FROM tn_property_images i
                  WHERE i.property_id = :property_id_exists
                    AND i.image_url = a.public_url
              )
            ORDER BY r.role = "cover" DESC, r.sort_order, a.id
        ');
        $media->execute([
            'property_id' => $propertyId,
            'property_id_exists' => $propertyId,
        ]);
        $items = $media->fetchAll(PDO::FETCH_ASSOC);

        if (!$items) {
            return;
        }

        $insert = $pdo->prepare('
            INSERT INTO tn_property_images (property_id, image_url, alt_text, sort_order, is_cover, created_at)
            VALUES (:property_id, :image_url, :alt_text, :sort_order, :is_cover, :created_at)
        ');
        $coverId = 0;
        $sortOrder = $this->nextSortOrder($pdo, $propertyId);

        foreach ($items as $item) {
            $imageUrl = $this->limit((string) ($item['public_url'] ?? ''), 700);
            if ($imageUrl === '') {
                continue;
            }

            $isCover = (string) ($item['role'] ?? '') === 'cover' ? 1 : 0;
            $insert->execute([
                'property_id' => $propertyId,
                'image_url' => $imageUrl,
                'alt_text' => $this->limit((string) ($item['original_name'] ?? ''), 220),
                'sort_order' => $isCover ? 5 : max($sortOrder, (int) ($item['sort_order'] ?? 0)),
                'is_cover' => $isCover,
                'created_at' => $item['created_at'] ?? date('Y-m-d H:i:s'),
            ]);

            if ($isCover && $coverId <= 0) {
                $coverId = (int) $pdo->lastInsertId();
            }

            $sortOrder += 10;
        }

        $this->ensureCover($pdo, $propertyId, $coverId);
    }

    public function agents(): array
    {
        return $this->database->fetchAll('
            SELECT id, public_name, role, email, phone
            FROM tn_agents
            WHERE is_active = 1
            ORDER BY public_name, id
        ');
    }

    public function propertyGroups(): array
    {
        return $this->database->fetchAll('
            SELECT g.id, g.title, g.slug, g.group_type, g.location_id, g.address, g.status,
                   l.city, l.region,
                   COUNT(p.id) AS property_count
            FROM tn_property_groups g
            INNER JOIN tn_locations l ON l.id = g.location_id
            LEFT JOIN tn_properties p ON p.property_group_id = g.id
            WHERE g.status = "active"
            GROUP BY g.id
            ORDER BY l.city, g.sort_order, g.title
        ');
    }

    public function propertyGroup(int $id): ?array
    {
        return $this->database->fetchOne('
            SELECT g.*,
                   l.city, l.region,
                   COUNT(p.id) AS property_count,
                   SUM(p.status = "published") AS published_count,
                   SUM(p.status = "draft") AS draft_count,
                   SUM(p.status = "reserved") AS reserved_count,
                   SUM(p.status = "sold") AS sold_count
            FROM tn_property_groups g
            INNER JOIN tn_locations l ON l.id = g.location_id
            LEFT JOIN tn_properties p ON p.property_group_id = g.id
            WHERE g.id = :id
            GROUP BY g.id
            LIMIT 1
        ', ['id' => $id]);
    }

    public function propertyGroupProperties(int $groupId): array
    {
        if ($groupId <= 0) {
            return [];
        }

        return $this->adminProperties($this->adminFilters([
            'property_group_id' => $groupId,
            'sort' => 'updated',
        ]));
    }

    public function updatePropertyGroup(int $groupId, array $input): array
    {
        $pdo = $this->database->connection();

        try {
            $pdo->beginTransaction();

            $group = $this->propertyGroupForUpdate($pdo, $groupId);
            if (!$group) {
                $pdo->rollBack();

                return ['ok' => false, 'message' => 'Групу об’єктів не знайдено.'];
            }

            $title = $this->limit((string) ($input['title'] ?? ''), 220);
            if ($title === '') {
                $pdo->rollBack();

                return ['ok' => false, 'message' => 'Вкажіть назву групи.'];
            }

            $locationId = $this->existingLocationId($pdo, (int) ($input['location_id'] ?? 0), (int) $group['location_id']);
            $slug = $this->slugValue($title);
            if ($slug === '') {
                $slug = 'property-group-' . $groupId;
            }

            $data = [
                'id' => $groupId,
                'title' => $title,
                'slug' => $this->uniqueGroupSlug($pdo, $slug, $groupId),
                'group_type' => $this->allowed((string) ($input['group_type'] ?? $group['group_type']), ['address', 'building', 'complex', 'project', 'location'], (string) $group['group_type']),
                'location_id' => $locationId,
                'address' => $this->nullable((string) ($input['address'] ?? ''), 255),
                'description' => $this->nullableText((string) ($input['description'] ?? '')),
                'status' => $this->allowed((string) ($input['status'] ?? $group['status']), ['active', 'archived'], (string) $group['status']),
            ];

            $statement = $pdo->prepare('
                UPDATE tn_property_groups
                SET title = :title,
                    slug = :slug,
                    group_type = :group_type,
                    location_id = :location_id,
                    address = :address,
                    description = :description,
                    status = :status,
                    updated_at = NOW()
                WHERE id = :id
                LIMIT 1
            ');
            $statement->execute($data);

            $pdo->commit();

            return ['ok' => true, 'message' => 'Групу об’єктів оновлено.'];
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }

            $this->logError('property-group-update', $e);

            return ['ok' => false, 'message' => 'Не вдалося оновити групу. Деталі записано в лог.'];
        }
    }

    public function activities(int $propertyId, int $limit = 20): array
    {
        $limit = max(1, min(50, $limit));

        return $this->database->fetchAll('
            SELECT a.id, a.activity_type, a.title, a.body, a.old_value, a.new_value, a.created_at,
                   u.full_name AS user_name
            FROM tn_property_activities a
            LEFT JOIN tn_users u ON u.id = a.user_id
            WHERE a.property_id = :property_id
            ORDER BY a.created_at DESC, a.id DESC
            LIMIT ' . $limit . '
        ', ['property_id' => $propertyId]);
    }

    public function inboundRequests(int $propertyId): array
    {
        return $this->database->fetchAll('
            SELECT l.id, l.full_name, l.phone, l.email, l.role, l.deal_type, l.status, l.source_page, l.created_at,
                   l.client_case_id,
                   c.public_id AS case_public_id, c.title AS case_title, c.stage AS case_stage, c.status AS case_status
            FROM tn_leads l
            LEFT JOIN tn_client_cases c ON c.id = l.client_case_id
            WHERE l.property_id = :property_id
            ORDER BY l.created_at DESC, l.id DESC
            LIMIT 30
        ', ['property_id' => $propertyId]);
    }

    public function caseMatches(int $propertyId): array
    {
        return $this->database->fetchAll('
            SELECT m.id, m.match_status, m.score, m.note, m.created_at, m.updated_at,
                   c.id AS case_id, c.public_id AS case_public_id, c.title AS case_title,
                   c.type AS case_type, c.stage AS case_stage, c.status AS case_status,
                   p.full_name, p.phone, p.email
            FROM tn_client_case_property_matches m
            INNER JOIN tn_client_cases c ON c.id = m.client_case_id
            INNER JOIN tn_people p ON p.id = c.person_id
            WHERE m.property_id = :property_id
            ORDER BY FIELD(m.match_status, "interested", "viewing", "sent", "suggested", "deal", "rejected"), m.updated_at DESC
            LIMIT 30
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
            'property_group_id' => max(0, (int) ($query['property_group_id'] ?? 0)),
            'agent_id' => max(0, (int) ($query['agent_id'] ?? 0)),
            'source_type' => $this->allowed((string) ($query['source_type'] ?? ''), ['own', 'partner', 'realtor', 'owner', 'developer'], ''),
            'operational_stage' => $this->operationalStage((string) ($query['operational_stage'] ?? '')),
            'quality' => $this->allowed((string) ($query['quality'] ?? ''), ['ready', 'not_ready', 'no_agent', 'no_photo', 'needs_status_note', 'overdue_action', 'no_next_action', 'stage_blocked'], ''),
            'sort' => $this->allowed((string) ($query['sort'] ?? ''), ['newest', 'updated', 'price_desc', 'price_asc', 'next_action'], 'updated'),
        ];
    }

    public function adminProperties(array $filters): array
    {
        $where = ['1 = 1'];
        $params = [];

        if (($filters['q'] ?? '') !== '') {
            $where[] = '(p.title LIKE :q OR p.public_id LIKE :q OR p.slug LIKE :q OR p.address LIKE :q OR l.city LIKE :q OR g.title LIKE :q OR g.address LIKE :q)';
            $params['q'] = '%' . $filters['q'] . '%';
        }

        foreach (['status', 'deal_type', 'source_type'] as $field) {
            if (($filters[$field] ?? '') !== '') {
                $where[] = 'p.' . $field . ' = :' . $field;
                $params[$field] = $filters[$field];
            }
        }

        if (($filters['operational_stage'] ?? '') !== '') {
            $where[] = 'p.operational_stage = :operational_stage';
            $params['operational_stage'] = $filters['operational_stage'];
        }

        foreach (['type_id', 'location_id', 'property_group_id', 'agent_id'] as $field) {
            if ((int) ($filters[$field] ?? 0) > 0) {
                $where[] = 'p.' . $field . ' = :' . $field;
                $params[$field] = (int) $filters[$field];
            }
        }

        $orderBy = match ($filters['sort'] ?? 'updated') {
            'newest' => 'p.id DESC',
            'price_desc' => 'p.price_amount IS NULL, p.price_amount DESC, p.updated_at DESC',
            'price_asc' => 'p.price_amount IS NULL, p.price_amount ASC, p.updated_at DESC',
            'next_action' => 'p.next_action_due_at IS NULL, p.next_action_due_at ASC, p.updated_at DESC',
            default => 'p.updated_at DESC, p.id DESC',
        };

        $properties = $this->database->fetchAll('
            SELECT
                p.id, p.public_id, p.slug, p.title, p.deal_type, p.status, p.source_type,
                p.type_id, p.location_id, p.property_group_id,
                p.price_amount, p.price_currency, p.price_period, p.area_total, p.land_area, p.rooms,
                p.short_description, p.description, p.meta_title, p.meta_description,
                p.manager_note, p.source_note, p.status_note, p.status_changed_at,
                p.operational_stage, p.next_action_title, p.next_action_due_at, p.next_action_note,
                p.agent_id, p.is_featured, p.has_3d_tour, p.published_at, p.updated_at,
                t.name_uk AS type_name,
                l.city, l.region,
                g.title AS group_title, g.slug AS group_slug, g.group_type, g.address AS group_address,
                a.public_name AS agent_name,
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
            LEFT JOIN tn_property_groups g ON g.id = p.property_group_id
            LEFT JOIN tn_agents a ON a.id = p.agent_id
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

        foreach ($properties as &$property) {
            $readiness = $this->readinessForData($property, (int) ($property['image_count'] ?? 0));
            $property['is_ready_to_publish'] = $readiness['ready'] ? 1 : 0;
            $property['readiness_missing_count'] = count($readiness['missing']);
            $property['readiness_missing'] = $readiness['missing'];
            $property['operational_stage_label'] = $this->operationalStageLabel((string) ($property['operational_stage'] ?? 'intake'));
            $property['operational_stage_issues'] = $this->operationalStageIssues($property, (int) ($property['image_count'] ?? 0));
            $property['quality_issues'] = $this->qualityIssues($property);
        }
        unset($property);

        $quality = (string) ($filters['quality'] ?? '');
        if ($quality !== '') {
            $properties = array_values(array_filter($properties, fn(array $property): bool => $this->matchesQuality($property, $quality)));
        }

        return $properties;
    }

    public function adminQualityStats(array $filters): array
    {
        $baseFilters = $filters;
        $baseFilters['quality'] = '';
        $properties = $this->adminProperties($baseFilters);
        $stats = [
            'all' => count($properties),
            'ready' => 0,
            'not_ready' => 0,
            'no_agent' => 0,
            'no_photo' => 0,
            'needs_status_note' => 0,
            'overdue_action' => 0,
            'no_next_action' => 0,
            'stage_blocked' => 0,
        ];

        foreach ($properties as $property) {
            foreach (['ready', 'not_ready', 'no_agent', 'no_photo', 'needs_status_note', 'overdue_action', 'no_next_action', 'stage_blocked'] as $quality) {
                if ($this->matchesQuality($property, $quality)) {
                    $stats[$quality]++;
                }
            }
        }

        return $stats;
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

    public function operationalStageRules(): array
    {
        return self::OPERATIONAL_STAGE_RULES;
    }

    public function operationalStageCheck(int $propertyId): array
    {
        $property = $this->property($propertyId);
        if (!$property) {
            return [
                'stage' => 'intake',
                'label' => $this->operationalStageLabel('intake'),
                'issues' => ['обʼєкт не знайдено'],
            ];
        }

        $stage = $this->operationalStage((string) ($property['operational_stage'] ?? 'intake')) ?: 'intake';

        return [
            'stage' => $stage,
            'label' => $this->operationalStageLabel($stage),
            'issues' => $this->operationalStageIssues($property, count($this->images($propertyId))),
        ];
    }

    public function createDraft(array $input, ?int $userId = null, array $files = []): array
    {
        $pdo = $this->database->connection();

        try {
            $pdo->beginTransaction();

            $title = $this->limit((string) ($input['title'] ?? ''), 220);
            if ($title === '') {
                $pdo->rollBack();

                return ['ok' => false, 'message' => 'Назва об’єкта обов’язкова.'];
            }

            $typeId = $this->existingTypeId($pdo, (int) ($input['type_id'] ?? 0), 0);
            $locationId = $this->existingLocationId($pdo, (int) ($input['location_id'] ?? 0), 0);

            if ($typeId <= 0 || $locationId <= 0) {
                $pdo->rollBack();

                return ['ok' => false, 'message' => 'Оберіть тип об’єкта і локацію.'];
            }

            $agentId = $this->existingAgentId($pdo, (int) ($input['agent_id'] ?? 0), null);
            $groupId = $this->resolvePropertyGroupId($pdo, (int) ($input['property_group_id'] ?? 0), $locationId, (string) ($input['new_group_title'] ?? ''), (string) ($input['address'] ?? ''));
            $publicId = $this->nextPublicId($pdo);
            $slug = $this->slugValue($title);
            if ($slug === '') {
                $slug = mb_strtolower($publicId);
            }

            $stage = $this->operationalStage((string) ($input['operational_stage'] ?? 'intake')) ?: 'intake';
            $data = [
                'public_id' => $publicId,
                'slug' => $this->uniqueSlug($pdo, $slug, 0),
                'title' => $title,
                'deal_type' => $this->allowed((string) ($input['deal_type'] ?? 'sale'), ['sale', 'rent', 'investment'], 'sale'),
                'type_id' => $typeId,
                'status' => 'draft',
                'source_type' => $this->allowed((string) ($input['source_type'] ?? 'own'), ['own', 'partner', 'realtor', 'owner', 'developer'], 'own'),
                'location_id' => $locationId,
                'property_group_id' => $groupId,
                'agent_id' => $agentId,
                'price_amount' => $this->decimalOrNull($input['price_amount'] ?? null),
                'price_currency' => $this->allowed((string) ($input['price_currency'] ?? 'USD'), ['USD', 'EUR', 'UAH'], 'USD'),
                'price_period' => $this->allowed((string) ($input['price_period'] ?? 'total'), ['total', 'month', 'day'], 'total'),
                'area_total' => $this->decimalOrNull($input['area_total'] ?? null),
                'land_area' => $this->decimalOrNull($input['land_area'] ?? null),
                'rooms' => $this->decimalOrNull($input['rooms'] ?? null),
                'address' => $this->nullable((string) ($input['address'] ?? ''), 255),
                'short_description' => $this->nullable((string) ($input['short_description'] ?? ''), 500),
                'description' => $this->nullableText((string) ($input['description'] ?? '')),
                'manager_note' => $this->nullableText((string) ($input['manager_note'] ?? '')),
                'source_note' => $this->nullable((string) ($input['source_note'] ?? ''), 500),
                'operational_stage' => $stage,
                'next_action_title' => $this->nullable((string) ($input['next_action_title'] ?? ''), 180),
                'next_action_due_at' => $this->dateTimeOrNull((string) ($input['next_action_due_at'] ?? '')),
                'next_action_note' => $this->nullable((string) ($input['next_action_note'] ?? ''), 500),
            ];

            $stageIssues = $this->operationalStageIssues($data, $this->uploadedImageCount($files));
            if ($stageIssues !== []) {
                $pdo->rollBack();

                return [
                    'ok' => false,
                    'message' => 'Етап "' . $this->operationalStageLabel($stage) . '" поки недоступний: ' . implode(', ', $stageIssues) . '.',
                ];
            }

            $statement = $pdo->prepare('
                INSERT INTO tn_properties (
                    public_id, slug, title, deal_type, type_id, status, source_type, location_id, agent_id,
                    property_group_id,
                    price_amount, price_currency, price_period, area_total, land_area, rooms,
                    address, short_description, description, manager_note, source_note,
                    operational_stage, next_action_title, next_action_due_at, next_action_note
                ) VALUES (
                    :public_id, :slug, :title, :deal_type, :type_id, :status, :source_type, :location_id, :agent_id,
                    :property_group_id,
                    :price_amount, :price_currency, :price_period, :area_total, :land_area, :rooms,
                    :address, :short_description, :description, :manager_note, :source_note,
                    :operational_stage, :next_action_title, :next_action_due_at, :next_action_note
                )
            ');
            $statement->execute($data);

            $propertyId = (int) $pdo->lastInsertId();
            $uploaded = $this->mediaStorage->storeUploadedFiles($files, 'property', $propertyId);
            $uploadedCoverId = $this->insertUploadedImages($pdo, $propertyId, $uploaded, $title);
            $this->ensureCover($pdo, $propertyId, (int) $uploadedCoverId);

            $this->insertActivity(
                $pdo,
                $propertyId,
                $userId,
                'system',
                'Чернетку об’єкта створено',
                'Створено менеджером з внутрішньої форми.'
            );

            $pdo->commit();

            return [
                'ok' => true,
                'message' => 'Чернетку об’єкта створено.',
                'property_id' => $propertyId,
            ];
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }

            $this->logError('property-create-draft', $e);

            return ['ok' => false, 'message' => $this->publicErrorMessage($e, 'Не вдалося створити об’єкт. Деталі записано в лог.')];

            return ['ok' => false, 'message' => 'Не вдалося створити об’єкт. Деталі записано в лог.'];
        }
    }

    public function updateStatus(int $propertyId, string $status, string $note = '', ?int $userId = null): array
    {
        $status = $this->allowed($status, ['draft', 'moderation', 'published', 'reserved', 'sold', 'archived'], '');
        if ($status === '') {
            return ['ok' => false, 'message' => 'Невідомий статус об’єкта.'];
        }

        $pdo = $this->database->connection();
        $note = $this->nullable((string) $note, 500);

        if ($this->requiresStatusNote($status) && $note === null) {
            return ['ok' => false, 'message' => 'Для цього статусу вкажіть причину або короткий контекст.'];
        }

        try {
            $pdo->beginTransaction();

            $property = $this->propertyForUpdate($pdo, $propertyId);
            if (!$property) {
                $pdo->rollBack();

                return ['ok' => false, 'message' => 'Об’єкт не знайдено.'];
            }

            $imageCount = $this->imageCount($pdo, $propertyId);

            if ($status === 'published') {
                $readiness = $this->readinessForData($property, $imageCount);
                if (!$readiness['ready']) {
                    $pdo->rollBack();

                    return [
                        'ok' => false,
                        'message' => 'Обʼєкт не готовий до публікації: ' . implode(', ', $readiness['missing']) . '.',
                    ];
                }
            }

            $stageData = $this->syncOperationalStageWithStatus(array_merge($property, [
                'status' => $status,
                'status_note' => $note,
            ]));
            $operationalStage = (string) ($stageData['operational_stage'] ?? ($property['operational_stage'] ?? 'intake'));
            $stageIssues = $this->operationalStageIssues($stageData, $imageCount);
            if ($stageIssues !== []) {
                $pdo->rollBack();

                return [
                    'ok' => false,
                    'message' => 'Операційний етап "' . $this->operationalStageLabel($operationalStage) . '" поки недоступний: ' . implode(', ', $stageIssues) . '.',
                ];
            }

            $statusChangedAt = $status !== (string) ($property['status'] ?? '')
                ? date('Y-m-d H:i:s')
                : ($property['status_changed_at'] ?? null);

            $statement = $pdo->prepare('
                UPDATE tn_properties
                SET status = :status,
                    status_note = :status_note,
                    status_changed_at = :status_changed_at,
                    operational_stage = :operational_stage,
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
                'status_note' => $note,
                'status_changed_at' => $statusChangedAt,
                'operational_stage' => $operationalStage,
                'status_for_publish' => $status,
            ]);

            if ($status !== (string) ($property['status'] ?? '') || $note !== ($property['status_note'] ?? null)) {
                $this->insertActivity(
                    $pdo,
                    $propertyId,
                    $userId,
                    'status_change',
                    'Статус об’єкта змінено',
                    $note,
                    (string) ($property['status'] ?? ''),
                    $status
                );
            }

            if ($operationalStage !== (string) ($property['operational_stage'] ?? '')) {
                $this->insertActivity(
                    $pdo,
                    $propertyId,
                    $userId,
                    'stage_change',
                    'Операційний етап змінено',
                    null,
                    $this->operationalStageLabel((string) ($property['operational_stage'] ?? 'intake')),
                    $this->operationalStageLabel($operationalStage)
                );
            }

            $pdo->commit();

            return ['ok' => true, 'message' => 'Статус об’єкта оновлено.'];
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }

            $this->logError('property-status-update', $e);

            return ['ok' => false, 'message' => 'Не вдалося оновити статус. Деталі записано в лог.'];
        }
    }

    public function updateDetails(int $propertyId, array $input, ?int $userId = null): array
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

            $slugSource = array_key_exists('slug', $input) ? (string) $input['slug'] : (string) ($property['slug'] ?? $title);
            $slug = $this->slugValue($slugSource);
            if ($slug === '') {
                $slug = 'property-' . $propertyId;
            }

            $status = $this->allowed((string) ($input['status'] ?? $property['status']), ['draft', 'moderation', 'published', 'reserved', 'sold', 'archived'], (string) $property['status']);
            $agentFallback = (int) ($property['agent_id'] ?? 0) > 0 ? (int) $property['agent_id'] : null;
            $agentInput = array_key_exists('agent_id', $input) ? (int) $input['agent_id'] : $agentFallback;
            $locationId = $this->existingLocationId($pdo, (int) ($input['location_id'] ?? 0), (int) $property['location_id']);
            $groupFallback = (int) ($property['property_group_id'] ?? 0);
            $groupId = $this->resolvePropertyGroupId(
                $pdo,
                array_key_exists('property_group_id', $input) ? (int) $input['property_group_id'] : $groupFallback,
                $locationId,
                (string) ($input['new_group_title'] ?? ''),
                (string) ($input['address'] ?? ($property['address'] ?? ''))
            );
            $statusChangedAt = $status !== (string) ($property['status'] ?? '')
                ? date('Y-m-d H:i:s')
                : ($property['status_changed_at'] ?? null);

            $data = [
                'id' => $propertyId,
                'slug' => $this->uniqueSlug($pdo, $slug, $propertyId),
                'title' => $title,
                'deal_type' => $this->allowed((string) ($input['deal_type'] ?? $property['deal_type']), ['sale', 'rent', 'investment'], (string) $property['deal_type']),
                'type_id' => $this->existingTypeId($pdo, (int) ($input['type_id'] ?? 0), (int) $property['type_id']),
                'status' => $status,
                'source_type' => $this->allowed((string) ($input['source_type'] ?? $property['source_type']), ['own', 'partner', 'realtor', 'owner', 'developer'], (string) $property['source_type']),
                'location_id' => $locationId,
                'property_group_id' => $groupId,
                'agent_id' => $this->existingAgentId($pdo, $agentInput, $agentFallback),
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
                'manager_note' => $this->nullableText((string) ($input['manager_note'] ?? '')),
                'source_note' => $this->nullable((string) ($input['source_note'] ?? ''), 500),
                'status_note' => $this->nullable((string) ($input['status_note'] ?? ''), 500),
                'status_changed_at' => $statusChangedAt,
                'operational_stage' => $this->operationalStage((string) ($input['operational_stage'] ?? ($property['operational_stage'] ?? 'intake'))) ?: 'intake',
                'next_action_title' => $this->nullable((string) ($input['next_action_title'] ?? ''), 180),
                'next_action_due_at' => $this->dateTimeOrNull((string) ($input['next_action_due_at'] ?? '')),
                'next_action_note' => $this->nullable((string) ($input['next_action_note'] ?? ''), 500),
                'status_for_publish' => $status,
            ];
            $data = $this->syncOperationalStageWithStatus($data);

            if ($this->requiresStatusNote($data['status']) && $data['status_note'] === null) {
                $pdo->rollBack();

                return ['ok' => false, 'message' => 'Для цього статусу вкажіть причину або короткий контекст.'];
            }

            $imageCount = $this->imageCount($pdo, $propertyId);
            if ($data['status'] === 'published') {
                $readiness = $this->readinessForData($data, $imageCount);
                if (!$readiness['ready']) {
                    $pdo->rollBack();

                    return [
                        'ok' => false,
                        'message' => 'Обʼєкт не готовий до публікації: ' . implode(', ', $readiness['missing']) . '.',
                    ];
                }
            }

            $stageIssues = $this->operationalStageIssues($data, $imageCount);
            if ($stageIssues !== []) {
                $pdo->rollBack();

                return [
                    'ok' => false,
                    'message' => 'Етап "' . $this->operationalStageLabel((string) $data['operational_stage']) . '" поки недоступний: ' . implode(', ', $stageIssues) . '.',
                ];
            }

            $statement = $pdo->prepare('
                UPDATE tn_properties
                SET slug = :slug,
                    title = :title,
                    deal_type = :deal_type,
                    type_id = :type_id,
                    status = :status,
                    source_type = :source_type,
                    location_id = :location_id,
                    property_group_id = :property_group_id,
                    agent_id = :agent_id,
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
                    manager_note = :manager_note,
                    source_note = :source_note,
                    status_note = :status_note,
                    status_changed_at = :status_changed_at,
                    operational_stage = :operational_stage,
                    next_action_title = :next_action_title,
                    next_action_due_at = :next_action_due_at,
                    next_action_note = :next_action_note,
                    published_at = CASE
                        WHEN :status_for_publish = "published" AND published_at IS NULL THEN NOW()
                        ELSE published_at
                    END,
                    updated_at = NOW()
                WHERE id = :id
                LIMIT 1
            ');
            $statement->execute($data);

            $changed = $this->changedLabels($property, $data, [
                'title' => 'назва',
                'deal_type' => 'операція',
                'type_id' => 'тип',
                'source_type' => 'джерело',
                'location_id' => 'локація',
                'property_group_id' => 'група об’єктів',
                'agent_id' => 'відповідальний агент',
                'price_amount' => 'ціна',
                'area_total' => 'площа',
                'short_description' => 'короткий опис',
                'description' => 'повний опис',
                'meta_title' => 'SEO title',
                'meta_description' => 'SEO description',
                'manager_note' => 'внутрішня нотатка',
                'source_note' => 'примітка джерела',
                'operational_stage' => 'операційний етап',
                'next_action_title' => 'наступна дія',
                'next_action_due_at' => 'дедлайн наступної дії',
                'next_action_note' => 'примітка наступної дії',
            ]);

            if ($data['status'] !== (string) ($property['status'] ?? '') || $data['status_note'] !== ($property['status_note'] ?? null)) {
                $this->insertActivity(
                    $pdo,
                    $propertyId,
                    $userId,
                    'status_change',
                    'Статус об’єкта змінено',
                    $data['status_note'],
                    (string) ($property['status'] ?? ''),
                    (string) $data['status']
                );
            }

            if ($changed !== []) {
                $this->insertActivity(
                    $pdo,
                    $propertyId,
                    $userId,
                    'details_update',
                    'Картку об’єкта оновлено',
                    'Змінено: ' . implode(', ', $changed) . '.'
                );
            }

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

    public function update(int $propertyId, array $input, array $files, ?int $userId = null): array
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

            $this->insertActivity(
                $pdo,
                $propertyId,
                $userId,
                'media_update',
                'Медіа об’єкта оновлено',
                'Оновлено галерею, порядок або головне фото.'
            );

            $pdo->commit();

            return ['ok' => true, 'message' => 'Медіа об’єкта оновлено.'];
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }

            $this->logError('property-media-update', $e);

            return ['ok' => false, 'message' => $this->publicErrorMessage($e, 'Не вдалося оновити медіа. Деталі записано в лог.')];

            return ['ok' => false, 'message' => 'Не вдалося оновити медіа. Деталі записано в лог.'];
        }
    }

    public function addActivityNote(int $propertyId, array $input, ?int $userId = null): array
    {
        $title = $this->limit((string) ($input['activity_title'] ?? ''), 180);
        $body = $this->nullableText((string) ($input['activity_body'] ?? ''));

        if ($title === '') {
            return ['ok' => false, 'message' => 'Вкажіть коротку назву нотатки.'];
        }

        if ($body === null) {
            return ['ok' => false, 'message' => 'Додайте текст нотатки.'];
        }

        $pdo = $this->database->connection();

        try {
            $pdo->beginTransaction();

            if (!$this->propertyForUpdate($pdo, $propertyId)) {
                $pdo->rollBack();

                return ['ok' => false, 'message' => 'Об’єкт не знайдено.'];
            }

            $this->insertActivity(
                $pdo,
                $propertyId,
                $userId,
                'note',
                $title,
                $body
            );

            $touch = $pdo->prepare('UPDATE tn_properties SET updated_at = NOW() WHERE id = :id LIMIT 1');
            $touch->execute(['id' => $propertyId]);

            $pdo->commit();

            return ['ok' => true, 'message' => 'Нотатку додано в журнал об’єкта.'];
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }

            $this->logError('property-activity-note', $e);

            return ['ok' => false, 'message' => 'Не вдалося додати нотатку. Деталі записано в лог.'];
        }
    }

    public function quickAction(int $propertyId, string $action, array $input = [], ?int $userId = null): array
    {
        $action = $this->allowed($action, ['publish', 'next_stage', 'schedule_action', 'share_link'], '');
        if ($action === '') {
            return ['ok' => false, 'message' => 'Невідома швидка дія.'];
        }

        if ($action === 'publish') {
            return $this->updateStatus($propertyId, 'published', (string) ($input['status_note'] ?? ''), $userId);
        }

        $pdo = $this->database->connection();

        try {
            $pdo->beginTransaction();

            $property = $this->propertyForUpdate($pdo, $propertyId);
            if (!$property) {
                $pdo->rollBack();

                return ['ok' => false, 'message' => 'Обʼєкт не знайдено.'];
            }

            if ($action === 'share_link') {
                $this->insertActivity(
                    $pdo,
                    $propertyId,
                    $userId,
                    'share',
                    'Посилання відправлено клієнту',
                    $this->nullableText((string) ($input['activity_body'] ?? 'Публічне посилання на картку обʼєкта передано клієнту.'))
                );

                $touch = $pdo->prepare('UPDATE tn_properties SET updated_at = NOW() WHERE id = :id LIMIT 1');
                $touch->execute(['id' => $propertyId]);
                $pdo->commit();

                return ['ok' => true, 'message' => 'Відправку посилання зафіксовано в журналі.'];
            }

            if ($action === 'schedule_action') {
                $nextActionTitle = $this->nullable((string) ($input['next_action_title'] ?? ''), 180);
                $nextActionDueAt = $this->dateTimeOrNull((string) ($input['next_action_due_at'] ?? ''));
                $nextActionNote = $this->nullable((string) ($input['next_action_note'] ?? ''), 500);

                if ($nextActionTitle === null || $nextActionDueAt === null) {
                    $pdo->rollBack();

                    return ['ok' => false, 'message' => 'Вкажіть наступну дію і дедлайн.'];
                }

                $statement = $pdo->prepare('
                    UPDATE tn_properties
                    SET next_action_title = :next_action_title,
                        next_action_due_at = :next_action_due_at,
                        next_action_note = :next_action_note,
                        updated_at = NOW()
                    WHERE id = :id
                    LIMIT 1
                ');
                $statement->execute([
                    'id' => $propertyId,
                    'next_action_title' => $nextActionTitle,
                    'next_action_due_at' => $nextActionDueAt,
                    'next_action_note' => $nextActionNote,
                ]);

                $this->insertActivity(
                    $pdo,
                    $propertyId,
                    $userId,
                    'next_action',
                    'Наступну дію заплановано',
                    $nextActionTitle . ' / ' . $nextActionDueAt,
                    (string) ($property['next_action_title'] ?? ''),
                    $nextActionTitle
                );

                $pdo->commit();

                return ['ok' => true, 'message' => 'Наступну дію заплановано.'];
            }

            $currentStage = $this->operationalStage((string) ($property['operational_stage'] ?? 'intake')) ?: 'intake';
            $currentIndex = array_search($currentStage, self::OPERATIONAL_STAGE_ORDER, true);
            $nextStage = $currentIndex === false ? '' : (string) (self::OPERATIONAL_STAGE_ORDER[$currentIndex + 1] ?? '');

            if ($nextStage === '') {
                $pdo->rollBack();

                return ['ok' => false, 'message' => 'Для цього обʼєкта немає наступного етапу.'];
            }

            if ($nextStage === 'published' && (string) ($property['status'] ?? '') !== 'published') {
                $pdo->rollBack();

                return ['ok' => false, 'message' => 'Для переходу в роботу на ринку спочатку опублікуйте обʼєкт.'];
            }

            $stageData = array_merge($property, [
                'operational_stage' => $nextStage,
                'next_action_title' => $this->nullable((string) ($input['next_action_title'] ?? ($property['next_action_title'] ?? '')), 180),
                'next_action_due_at' => $this->dateTimeOrNull((string) ($input['next_action_due_at'] ?? ($property['next_action_due_at'] ?? ''))),
                'next_action_note' => $this->nullable((string) ($input['next_action_note'] ?? ($property['next_action_note'] ?? '')), 500),
            ]);

            $imageCount = $this->imageCount($pdo, $propertyId);
            $issues = $this->operationalStageIssues($stageData, $imageCount);
            if ($issues !== []) {
                $pdo->rollBack();

                return [
                    'ok' => false,
                    'message' => 'Наступний етап "' . $this->operationalStageLabel($nextStage) . '" поки недоступний: ' . implode(', ', $issues) . '.',
                ];
            }

            $statement = $pdo->prepare('
                UPDATE tn_properties
                SET operational_stage = :operational_stage,
                    next_action_title = :next_action_title,
                    next_action_due_at = :next_action_due_at,
                    next_action_note = :next_action_note,
                    updated_at = NOW()
                WHERE id = :id
                LIMIT 1
            ');
            $statement->execute([
                'id' => $propertyId,
                'operational_stage' => $nextStage,
                'next_action_title' => $stageData['next_action_title'],
                'next_action_due_at' => $stageData['next_action_due_at'],
                'next_action_note' => $stageData['next_action_note'],
            ]);

            $this->insertActivity(
                $pdo,
                $propertyId,
                $userId,
                'stage_change',
                'Операційний етап змінено',
                $stageData['next_action_title'] ? 'Наступна дія: ' . $stageData['next_action_title'] : null,
                $this->operationalStageLabel($currentStage),
                $this->operationalStageLabel($nextStage)
            );

            $pdo->commit();

            return ['ok' => true, 'message' => 'Обʼєкт переведено на етап "' . $this->operationalStageLabel($nextStage) . '".'];
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }

            $this->logError('property-quick-action', $e);

            return ['ok' => false, 'message' => 'Швидку дію не вдалося виконати. Деталі записано в лог.'];
        }
    }

    public function readiness(int $propertyId): array
    {
        $property = $this->property($propertyId);
        if (!$property) {
            return [
                'ready' => false,
                'missing' => ['обʼєкт не знайдено'],
                'checks' => [],
            ];
        }

        return $this->readinessForData($property, count($this->images($propertyId)));
    }

    private function propertyForUpdate(PDO $pdo, int $propertyId): ?array
    {
        $statement = $pdo->prepare('SELECT * FROM tn_properties WHERE id = :id LIMIT 1 FOR UPDATE');
        $statement->execute(['id' => $propertyId]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);

        return $row ?: null;
    }

    private function propertyGroupForUpdate(PDO $pdo, int $groupId): ?array
    {
        $statement = $pdo->prepare('SELECT * FROM tn_property_groups WHERE id = :id LIMIT 1 FOR UPDATE');
        $statement->execute(['id' => $groupId]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);

        return $row ?: null;
    }

    private function readinessForData(array $property, int $imageCount): array
    {
        $priceNote = mb_strtolower((string) (($property['short_description'] ?? '') . ' ' . ($property['description'] ?? '')));
        $checks = [
            ['key' => 'title', 'label' => 'назва', 'ok' => trim((string) ($property['title'] ?? '')) !== ''],
            ['key' => 'slug', 'label' => 'slug', 'ok' => trim((string) ($property['slug'] ?? '')) !== ''],
            ['key' => 'type', 'label' => 'тип обʼєкта', 'ok' => (int) ($property['type_id'] ?? 0) > 0],
            ['key' => 'location', 'label' => 'локація', 'ok' => (int) ($property['location_id'] ?? 0) > 0],
            ['key' => 'agent', 'label' => 'відповідальний агент', 'ok' => (int) ($property['agent_id'] ?? 0) > 0],
            ['key' => 'summary', 'label' => 'короткий опис', 'ok' => trim((string) ($property['short_description'] ?? '')) !== ''],
            ['key' => 'description', 'label' => 'повний опис', 'ok' => trim((string) ($property['description'] ?? '')) !== ''],
            ['key' => 'price', 'label' => 'ціна або примітка про ціну за запитом', 'ok' => $this->hasPriceOrRequestNote($property, $priceNote)],
            ['key' => 'area', 'label' => 'площа або ділянка', 'ok' => (float) ($property['area_total'] ?? 0) > 0 || (float) ($property['land_area'] ?? 0) > 0],
            ['key' => 'media', 'label' => 'хоча б одне фото', 'ok' => $imageCount > 0],
            ['key' => 'seo_title', 'label' => 'SEO title', 'ok' => trim((string) ($property['meta_title'] ?? '')) !== ''],
            ['key' => 'seo_description', 'label' => 'SEO description', 'ok' => trim((string) ($property['meta_description'] ?? '')) !== ''],
        ];

        $missing = [];
        foreach ($checks as $check) {
            if (!$check['ok']) {
                $missing[] = $check['label'];
            }
        }

        return [
            'ready' => $missing === [],
            'missing' => $missing,
            'checks' => $checks,
        ];
    }

    private function hasPriceOrRequestNote(array $property, string $priceNote): bool
    {
        if ((float) ($property['price_amount'] ?? 0) > 0) {
            return true;
        }

        return str_contains($priceNote, 'ціна')
            && (str_contains($priceNote, 'запит') || str_contains($priceNote, 'уточ'));
    }

    private function requiresStatusNote(string $status): bool
    {
        return in_array($status, ['reserved', 'sold', 'archived'], true);
    }

    private function operationalStage(string $stage): string
    {
        $stage = trim($stage);

        return array_key_exists($stage, self::OPERATIONAL_STAGE_RULES) ? $stage : '';
    }

    private function operationalStageLabel(string $stage): string
    {
        $stage = $this->operationalStage($stage) ?: 'intake';

        return (string) (self::OPERATIONAL_STAGE_RULES[$stage]['label'] ?? $stage);
    }

    private function operationalStageRank(string $stage): int
    {
        $stage = $this->operationalStage($stage) ?: 'intake';
        $index = array_search($stage, self::OPERATIONAL_STAGE_ORDER, true);

        return $index === false ? 0 : (int) $index;
    }

    private function syncOperationalStageWithStatus(array $property): array
    {
        $status = (string) ($property['status'] ?? 'draft');
        $stage = $this->operationalStage((string) ($property['operational_stage'] ?? 'intake')) ?: 'intake';

        if ($status === 'archived') {
            $property['operational_stage'] = 'archived';

            return $property;
        }

        if ($status === 'sold') {
            if (!in_array($stage, ['deal', 'aftercare'], true)) {
                $property['operational_stage'] = 'deal';
            }

            return $property;
        }

        if ($status === 'reserved') {
            $property['operational_stage'] = 'reserved';

            return $property;
        }

        if ($status === 'published' && $this->operationalStageRank($stage) < $this->operationalStageRank('published')) {
            $property['operational_stage'] = 'published';
        }

        return $property;
    }

    private function operationalStageIssues(array $property, int $imageCount): array
    {
        $stage = $this->operationalStage((string) ($property['operational_stage'] ?? ''));
        if ($stage === '') {
            return ['невідомий етап роботи'];
        }

        $issues = [];
        $rank = $this->operationalStageRank($stage);
        $status = (string) ($property['status'] ?? 'draft');
        $statusNote = trim((string) ($property['status_note'] ?? ''));
        $priceNote = mb_strtolower((string) (($property['short_description'] ?? '') . ' ' . ($property['description'] ?? '')));

        if (!empty(self::OPERATIONAL_STAGE_RULES[$stage]['requires_next_action'])) {
            if (trim((string) ($property['next_action_title'] ?? '')) === '') {
                $issues[] = 'вкажіть наступну дію';
            }

            if (trim((string) ($property['next_action_due_at'] ?? '')) === '') {
                $issues[] = 'вкажіть дедлайн наступної дії';
            }
        }

        if ($rank >= $this->operationalStageRank('verification')) {
            if ((int) ($property['agent_id'] ?? 0) <= 0) {
                $issues[] = 'призначте відповідального';
            }

            if (trim((string) ($property['address'] ?? '')) === '' && (int) ($property['property_group_id'] ?? 0) <= 0) {
                $issues[] = 'вкажіть адресу або групу обʼєктів';
            }
        }

        if ($rank >= $this->operationalStageRank('pricing')) {
            if (!$this->hasPriceOrRequestNote($property, $priceNote)) {
                $issues[] = 'вкажіть ціну або примітку про ціну за запитом';
            }

            if ((float) ($property['area_total'] ?? 0) <= 0 && (float) ($property['land_area'] ?? 0) <= 0) {
                $issues[] = 'вкажіть площу або ділянку';
            }
        }

        if (in_array($stage, ['ready_to_publish', 'published', 'negotiation'], true)) {
            $readiness = $this->readinessForData($property, $imageCount);
            if (!$readiness['ready']) {
                $issues[] = 'не пройдено чекліст публікації: ' . implode(', ', $readiness['missing']);
            }
        }

        if (in_array($stage, ['published', 'negotiation'], true) && !in_array($status, ['published', 'reserved', 'sold'], true)) {
            $issues[] = 'спершу опублікуйте обʼєкт';
        }

        if ($stage === 'reserved' && $status !== 'reserved') {
            $issues[] = 'для етапу резерву потрібен статус "резерв"';
        }

        if (in_array($stage, ['deal', 'aftercare'], true) && $status !== 'sold') {
            $issues[] = 'для цього етапу потрібен статус "продано"';
        }

        if ($stage === 'archived' && $status !== 'archived') {
            $issues[] = 'для архівного етапу потрібен статус "архів"';
        }

        if ($this->requiresStatusNote($status) && $statusNote === '') {
            $issues[] = 'додайте причину або контекст статусу';
        }

        return array_values(array_unique($issues));
    }

    private function matchesQuality(array $property, string $quality): bool
    {
        return match ($quality) {
            'ready' => !empty($property['is_ready_to_publish']),
            'not_ready' => empty($property['is_ready_to_publish']),
            'no_agent' => (int) ($property['agent_id'] ?? 0) <= 0,
            'no_photo' => (int) ($property['image_count'] ?? 0) <= 0,
            'needs_status_note' => $this->requiresStatusNote((string) ($property['status'] ?? ''))
                && trim((string) ($property['status_note'] ?? '')) === '',
            'overdue_action' => $this->isActionOverdue($property['next_action_due_at'] ?? null),
            'no_next_action' => trim((string) ($property['next_action_title'] ?? '')) === '',
            'stage_blocked' => !empty($property['operational_stage_issues']),
            default => true,
        };
    }

    private function qualityIssues(array $property): array
    {
        $issues = [];

        if (empty($property['is_ready_to_publish'])) {
            $issues[] = 'не готовий: ' . (int) ($property['readiness_missing_count'] ?? 0);
        }

        if ($this->matchesQuality($property, 'no_agent')) {
            $issues[] = 'без відповідального';
        }

        if ($this->matchesQuality($property, 'no_photo')) {
            $issues[] = 'без фото';
        }

        if ($this->matchesQuality($property, 'needs_status_note')) {
            $issues[] = 'без причини статусу';
        }

        if ($this->matchesQuality($property, 'overdue_action')) {
            $issues[] = 'прострочена дія';
        }

        if ($this->matchesQuality($property, 'no_next_action')) {
            $issues[] = 'немає наступної дії';
        }

        foreach (($property['operational_stage_issues'] ?? []) as $issue) {
            $issues[] = 'етап: ' . $issue;
        }

        return $issues;
    }

    private function isActionOverdue(mixed $value): bool
    {
        $value = trim((string) $value);
        if ($value === '') {
            return false;
        }

        $timestamp = strtotime($value);
        return $timestamp !== false && $timestamp < time();
    }

    private function imageCount(PDO $pdo, int $propertyId): int
    {
        $statement = $pdo->prepare('SELECT COUNT(*) FROM tn_property_images WHERE property_id = :property_id');
        $statement->execute(['property_id' => $propertyId]);

        return (int) $statement->fetchColumn();
    }

    private function uploadedImageCount(array $files): int
    {
        $count = 0;
        foreach (['main_photo', 'gallery_photos'] as $fieldName) {
            $field = $files[$fieldName] ?? null;
            if (!is_array($field)) {
                continue;
            }

            $errors = $field['error'] ?? UPLOAD_ERR_NO_FILE;
            if (!is_array($errors)) {
                $errors = [$errors];
            }

            foreach ($errors as $error) {
                if ((int) $error === UPLOAD_ERR_OK) {
                    $count++;
                }
            }
        }

        return $count;
    }

    private function publicErrorMessage(Throwable $e, string $fallback): string
    {
        $message = trim($e->getMessage());
        if ($message !== '' && mb_strlen($message) <= 220) {
            return $message;
        }

        return $fallback;
    }

    private function existingAgentId(PDO $pdo, ?int $id, ?int $fallback): ?int
    {
        if (!$id || $id <= 0) {
            return null;
        }

        $statement = $pdo->prepare('SELECT id FROM tn_agents WHERE id = :id AND is_active = 1 LIMIT 1');
        $statement->execute(['id' => $id]);

        return $statement->fetchColumn() ? $id : $fallback;
    }

    private function changedLabels(array $old, array $new, array $labels): array
    {
        $changed = [];

        foreach ($labels as $key => $label) {
            if ($this->normalizedComparable($old[$key] ?? null) !== $this->normalizedComparable($new[$key] ?? null)) {
                $changed[] = $label;
            }
        }

        return $changed;
    }

    private function normalizedComparable(mixed $value): string
    {
        if ($value === null) {
            return '';
        }

        if (is_numeric($value)) {
            return rtrim(rtrim(number_format((float) $value, 7, '.', ''), '0'), '.');
        }

        return trim((string) $value);
    }

    private function insertActivity(
        PDO $pdo,
        int $propertyId,
        ?int $userId,
        string $type,
        string $title,
        ?string $body = null,
        ?string $oldValue = null,
        ?string $newValue = null
    ): void {
        $statement = $pdo->prepare('
            INSERT INTO tn_property_activities (property_id, user_id, activity_type, title, body, old_value, new_value)
            VALUES (:property_id, :user_id, :activity_type, :title, :body, :old_value, :new_value)
        ');
        $statement->execute([
            'property_id' => $propertyId,
            'user_id' => $userId && $userId > 0 ? $userId : null,
            'activity_type' => $type,
            'title' => $this->limit($title, 180),
            'body' => $body !== null ? $this->nullableText($body) : null,
            'old_value' => $oldValue !== null ? $this->limit($oldValue, 500) : null,
            'new_value' => $newValue !== null ? $this->limit($newValue, 500) : null,
        ]);
    }

    private function existingTypeId(PDO $pdo, int $id, int $fallback): int
    {
        return $this->existingId($pdo, 'tn_property_types', $id, $fallback);
    }

    private function existingLocationId(PDO $pdo, int $id, int $fallback): int
    {
        return $this->existingId($pdo, 'tn_locations', $id, $fallback);
    }

    private function resolvePropertyGroupId(PDO $pdo, int $groupId, int $locationId, string $newTitle, string $address = ''): ?int
    {
        $newTitle = $this->limit($newTitle, 220);
        if ($newTitle !== '') {
            return $this->createPropertyGroup($pdo, $locationId, $newTitle, $address);
        }

        if ($groupId <= 0) {
            return null;
        }

        $statement = $pdo->prepare('
            SELECT id
            FROM tn_property_groups
            WHERE id = :id AND status = "active"
            LIMIT 1
        ');
        $statement->execute(['id' => $groupId]);

        return $statement->fetchColumn() ? $groupId : null;
    }

    private function createPropertyGroup(PDO $pdo, int $locationId, string $title, string $address = ''): int
    {
        $slugBase = $this->slugValue($title);
        if ($slugBase === '') {
            $slugBase = 'property-group-' . date('ymd');
        }

        $statement = $pdo->prepare('
            INSERT INTO tn_property_groups (title, slug, group_type, location_id, address)
            VALUES (:title, :slug, "address", :location_id, :address)
        ');
        $statement->execute([
            'title' => $title,
            'slug' => $this->uniqueGroupSlug($pdo, $slugBase),
            'location_id' => $locationId,
            'address' => $this->nullable($address, 255),
        ]);

        return (int) $pdo->lastInsertId();
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

    private function nextPublicId(PDO $pdo): string
    {
        $statement = $pdo->prepare('SELECT id FROM tn_properties WHERE public_id = :public_id LIMIT 1');

        do {
            $publicId = 'TN-' . date('ymd') . '-' . strtoupper(bin2hex(random_bytes(3)));
            $statement->execute(['public_id' => $publicId]);
        } while ($statement->fetchColumn());

        return $publicId;
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

    private function uniqueGroupSlug(PDO $pdo, string $slug, int $groupId = 0): string
    {
        $base = $this->limit($slug, 170);
        $candidate = $base;
        $counter = 2;

        $statement = $pdo->prepare('SELECT id FROM tn_property_groups WHERE slug = :slug AND id <> :id LIMIT 1');

        while (true) {
            $statement->execute(['slug' => $candidate, 'id' => $groupId]);
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

    private function dateTimeOrNull(string $value): ?string
    {
        $value = trim($value);
        if ($value === '') {
            return null;
        }

        $timestamp = strtotime($value);
        if ($timestamp === false) {
            return null;
        }

        return date('Y-m-d H:i:s', $timestamp);
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
        $select = $pdo->prepare('SELECT image_url FROM tn_property_images WHERE property_id = ? AND id IN (' . $placeholders . ')');
        $select->execute(array_merge([$propertyId], $ids));
        $publicUrls = $select->fetchAll(PDO::FETCH_COLUMN);

        $statement = $pdo->prepare('DELETE FROM tn_property_images WHERE property_id = ? AND id IN (' . $placeholders . ')');
        $statement->execute(array_merge([$propertyId], $ids));

        $this->mediaStorage->markDeletedByPublicUrls('property', $propertyId, $publicUrls ?: []);
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
            $this->syncMediaRelationsFromPropertyImages($pdo, $propertyId);
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

        $this->syncMediaRelationsFromPropertyImages($pdo, $propertyId);
    }

    private function syncMediaRelationsFromPropertyImages(PDO $pdo, int $propertyId): void
    {
        $statement = $pdo->prepare('
            UPDATE tn_media_relations r
            INNER JOIN tn_media_assets a ON a.id = r.media_id
            INNER JOIN tn_property_images i ON i.image_url = a.public_url
            SET r.role = CASE WHEN i.is_cover = 1 THEN "cover" ELSE "gallery" END,
                r.sort_order = i.sort_order
            WHERE r.entity_type = "property"
              AND r.entity_id = :property_id
              AND i.property_id = :property_id_images
        ');
        $statement->execute([
            'property_id' => $propertyId,
            'property_id_images' => $propertyId,
        ]);
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
