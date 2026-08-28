<?php
declare(strict_types=1);

namespace Infrastructure\Persistence\MySql\Spatial;

use Domains\Spatial\Application\Contract\SpatialAssetStorageInterface;
use Domains\Spatial\Application\Contract\SpatialSceneInterface;
use Infrastructure\Database\Connection\DatabaseService;
use PDO;
use RuntimeException;
use Throwable;

class SpatialSceneService implements SpatialSceneInterface
{
    private const SCENE_TYPES = ['model', 'digital_twin', 'roomplan', 'panorama', 'gaussian_splat', 'mixed'];
    private const VIEWERS = ['threejs', 'external', 'panorama', 'gaussian_splat'];
    private const PROVIDERS = ['native', 'roomplan', 'polycam', 'matterport', 'canvas', 'scaniverse', 'blender', 'bim', 'other'];
    private const CAPTURE_METHODS = ['lidar', 'roomplan', 'photogrammetry', 'bim', 'cad', 'manual', 'provider'];
    private const HOTSPOT_TYPES = ['info', 'room', 'feature', 'cta', 'link', 'media', 'measurement', 'navigation'];
    private const EVENT_TYPES = ['view', 'load', 'start', 'complete', 'hotspot', 'ar_open', 'download', 'error'];

    public function __construct(private DatabaseService $database, private SpatialAssetStorageInterface $assets)
    {
    }

    public function managerScenes(array $filters = []): array
    {
        $status = trim((string) ($filters['status'] ?? ''));
        $search = mb_substr(trim((string) ($filters['q'] ?? '')), 0, 120);
        $where = [];
        $params = [];
        if (in_array($status, ['draft', 'processing', 'review', 'published', 'failed', 'archived'], true)) {
            $where[] = 's.status = :status';
            $params['status'] = $status;
        }
        if ($search !== '') {
            $where[] = '(s.title LIKE :search OR s.slug LIKE :search OR p.title LIKE :search OR p.public_id LIKE :search)';
            $params['search'] = '%' . $search . '%';
        }
        return $this->database->fetchAll('
            SELECT s.*, p.id AS property_id, p.title AS property_title, p.slug AS property_slug,
                   COUNT(DISTINCT a.id) AS asset_count,
                   SUM(a.status = "ready") AS ready_asset_count,
                   COUNT(DISTINCT h.id) AS hotspot_count
            FROM tn_spatial_scenes s
            LEFT JOIN tn_spatial_relations r ON r.scene_id = s.id AND r.entity_type = "property" AND r.role = "primary"
            LEFT JOIN tn_properties p ON p.id = r.entity_id
            LEFT JOIN tn_spatial_assets a ON a.scene_id = s.id AND a.status <> "archived"
            LEFT JOIN tn_spatial_hotspots h ON h.scene_id = s.id AND h.is_active = 1
            ' . ($where ? 'WHERE ' . implode(' AND ', $where) : '') . '
            GROUP BY s.id, p.id, p.title, p.slug
            ORDER BY FIELD(s.status, "processing", "review", "draft", "failed", "published", "archived"), s.updated_at DESC
            LIMIT 200
        ', $params);
    }

    public function stats(): array
    {
        $result = array_fill_keys(['draft', 'processing', 'review', 'published', 'failed', 'archived'], 0);
        foreach ($this->database->fetchAll('SELECT status, COUNT(*) AS total FROM tn_spatial_scenes GROUP BY status') as $row) {
            $result[(string) $row['status']] = (int) $row['total'];
        }
        $result['pending_jobs'] = (int) ($this->database->fetchOne(
            'SELECT COUNT(*) AS total FROM tn_spatial_processing_jobs WHERE status IN ("pending", "processing", "failed") AND attempts < 3'
        )['total'] ?? 0);
        return $result;
    }

    public function propertyOptions(): array
    {
        return $this->database->fetchAll('
            SELECT p.id, p.public_id, p.title, p.status, l.city
            FROM tn_properties p INNER JOIN tn_locations l ON l.id = p.location_id
            WHERE p.status <> "archived" ORDER BY p.updated_at DESC, p.id DESC LIMIT 500
        ');
    }

    public function save(array $input, array $user): array
    {
        $id = (int) ($input['id'] ?? 0);
        $title = mb_substr(trim((string) ($input['title'] ?? '')), 0, 220);
        if ($title === '') {
            return ['ok' => false, 'message' => 'Вкажіть назву Spatial сцени.', 'id' => $id];
        }
        $type = $this->allowed((string) ($input['scene_type'] ?? ''), self::SCENE_TYPES, 'model');
        $viewer = $this->allowed((string) ($input['viewer_type'] ?? ''), self::VIEWERS, 'threejs');
        $provider = $this->allowed((string) ($input['provider'] ?? ''), self::PROVIDERS, 'native');
        $externalUrl = $this->safeUrlOrNull((string) ($input['external_url'] ?? ''));
        if ($viewer === 'external' && !$externalUrl) {
            return ['ok' => false, 'message' => 'Для зовнішнього viewer потрібен URL.', 'id' => $id];
        }

        $pdo = $this->database->connection();
        try {
            $pdo->beginTransaction();
            $current = $id > 0 ? $this->sceneForUpdate($pdo, $id) : null;
            if ($id > 0 && !$current) {
                $pdo->rollBack();
                return ['ok' => false, 'message' => 'Spatial сцену не знайдено.', 'id' => $id];
            }
            $slug = $this->uniqueSlug($pdo, (string) ($input['slug'] ?? $title), $id);
            $values = [
                'slug' => $slug,
                'title' => $title,
                'description' => $this->nullable((string) ($input['description'] ?? ''), 20000),
                'scene_type' => $type,
                'viewer_type' => $viewer,
                'provider' => $provider,
                'external_url' => $externalUrl,
                'default_camera_json' => $this->jsonObject($input['default_camera_json'] ?? null),
                'settings_json' => $this->jsonObject($input['settings_json'] ?? null),
            ];
            if ($current) {
                $values['id'] = $id;
                $pdo->prepare('
                    UPDATE tn_spatial_scenes SET slug = :slug, title = :title, description = :description,
                        scene_type = :scene_type, viewer_type = :viewer_type, provider = :provider,
                        external_url = :external_url, default_camera_json = :default_camera_json,
                        settings_json = :settings_json
                    WHERE id = :id LIMIT 1
                ')->execute($values);
            } else {
                $values['public_id'] = $this->publicId('SPS');
                $values['created_by_user_id'] = (int) $user['id'];
                $pdo->prepare('
                    INSERT INTO tn_spatial_scenes (
                        public_id, slug, title, description, scene_type, viewer_type, provider,
                        external_url, default_camera_json, settings_json, created_by_user_id
                    ) VALUES (
                        :public_id, :slug, :title, :description, :scene_type, :viewer_type, :provider,
                        :external_url, :default_camera_json, :settings_json, :created_by_user_id
                    )
                ')->execute($values);
                $id = (int) $pdo->lastInsertId();
                $this->createVersion($pdo, $id, $input, $user);
            }
            $this->syncPropertyRelation($pdo, $id, (int) ($input['property_id'] ?? 0));
            $pdo->commit();
            return ['ok' => true, 'message' => 'Spatial сцену збережено.', 'id' => $id, 'slug' => $slug];
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $this->logError('scene-save', $e);
            return ['ok' => false, 'message' => 'Не вдалося зберегти Spatial сцену.', 'id' => $id];
        }
    }

    public function scene(int $id): ?array
    {
        if ($id <= 0) {
            return null;
        }
        $scene = $this->database->fetchOne('
            SELECT s.*, r.entity_id AS property_id, p.title AS property_title, p.slug AS property_slug,
                   pm.public_url AS poster_url
            FROM tn_spatial_scenes s
            LEFT JOIN tn_spatial_relations r ON r.scene_id = s.id AND r.entity_type = "property" AND r.role = "primary"
            LEFT JOIN tn_properties p ON p.id = r.entity_id
            LEFT JOIN tn_media_assets pm ON pm.id = s.poster_media_id
            WHERE s.id = :id LIMIT 1
        ', ['id' => $id]);
        return $scene ? $this->details($scene, false) : null;
    }

    public function sceneReference(int $id): ?array
    {
        return $this->database->fetchOne('SELECT id, public_id, slug, title, status, viewer_type FROM tn_spatial_scenes WHERE id = :id LIMIT 1', ['id' => $id]);
    }

    public function publicScene(string $reference): ?array
    {
        $scene = $this->database->fetchOne('
            SELECT s.*, pm.public_url AS poster_url
            FROM tn_spatial_scenes s
            LEFT JOIN tn_media_assets pm ON pm.id = s.poster_media_id
            WHERE (s.public_id = :reference OR s.slug = :reference)
              AND s.status = "published" AND s.published_at IS NOT NULL AND s.published_at <= NOW()
            LIMIT 1
        ', ['reference' => $reference]);
        return $scene ? $this->details($scene, true) : null;
    }

    public function sceneForProperty(int $propertyId, bool $publicOnly = true): ?array
    {
        $scene = $this->database->fetchOne('
            SELECT s.*, pm.public_url AS poster_url
            FROM tn_spatial_relations r
            INNER JOIN tn_spatial_scenes s ON s.id = r.scene_id
            LEFT JOIN tn_media_assets pm ON pm.id = s.poster_media_id
            WHERE r.entity_type = "property" AND r.entity_id = :property_id AND r.role = "primary"
              ' . ($publicOnly ? 'AND s.status = "published" AND s.published_at <= NOW()' : '') . '
            ORDER BY r.sort_order, s.updated_at DESC LIMIT 1
        ', ['property_id' => $propertyId]);
        return $scene ? $this->details($scene, $publicOnly) : null;
    }

    public function upload(int $sceneId, array $file, array $input, array $user): array
    {
        $scene = $this->sceneReference($sceneId);
        if (!$scene) {
            throw new RuntimeException('Spatial сцену не знайдено.');
        }
        return $this->assets->store($scene, $file, $input, $user);
    }

    public function externalAsset(int $sceneId, array $input): array
    {
        $scene = $this->sceneReference($sceneId);
        if (!$scene) {
            throw new RuntimeException('Spatial сцену не знайдено.');
        }
        return $this->assets->external($scene, $input);
    }

    public function capture(int $sceneId, array $input, array $user): array
    {
        if (!$this->sceneReference($sceneId)) {
            throw new RuntimeException('Spatial сцену не знайдено.');
        }
        $method = $this->allowed((string) ($input['capture_type'] ?? ''), self::CAPTURE_METHODS, 'manual');
        $pdo = $this->database->connection();
        $versionId = (int) ($input['version_id'] ?? 0);
        if ($versionId <= 0) {
            $next = (int) ($this->database->fetchOne('SELECT COALESCE(MAX(version_number), 0) + 1 AS next FROM tn_spatial_versions WHERE scene_id = :scene_id', ['scene_id' => $sceneId])['next'] ?? 1);
            $pdo->prepare('
                INSERT INTO tn_spatial_versions (
                    scene_id, version_number, label, capture_method, status, device_name, captured_at, notes, created_by_user_id
                ) VALUES (:scene_id, :version, :label, :method, "draft", :device, :captured_at, :notes, :user_id)
            ')->execute([
                'scene_id' => $sceneId,
                'version' => $next,
                'label' => $this->nullable((string) ($input['label'] ?? 'Версія ' . $next), 160),
                'method' => $method,
                'device' => $this->nullable((string) ($input['device_name'] ?? ''), 160),
                'captured_at' => $this->dateTime($input['captured_at'] ?? null),
                'notes' => $this->nullable((string) ($input['notes'] ?? ''), 10000),
                'user_id' => (int) $user['id'],
            ]);
            $versionId = (int) $pdo->lastInsertId();
        }
        $statement = $pdo->prepare('
            INSERT INTO tn_spatial_captures (
                public_id, scene_id, version_id, capture_type, provider, external_id, device_name,
                status, source_payload, captured_by_user_id, captured_at
            ) VALUES (
                :public_id, :scene_id, :version_id, :capture_type, :provider, :external_id, :device_name,
                "received", :source_payload, :user_id, :captured_at
            )
        ');
        $statement->execute([
            'public_id' => $this->publicId('SPC'),
            'scene_id' => $sceneId,
            'version_id' => $versionId,
            'capture_type' => $method,
            'provider' => $this->nullable((string) ($input['provider'] ?? ''), 80),
            'external_id' => $this->nullable((string) ($input['external_id'] ?? ''), 190),
            'device_name' => $this->nullable((string) ($input['device_name'] ?? ''), 160),
            'source_payload' => $this->jsonObject($input['source_payload'] ?? null),
            'user_id' => (int) $user['id'],
            'captured_at' => $this->dateTime($input['captured_at'] ?? null),
        ]);
        return $this->database->fetchOne('SELECT * FROM tn_spatial_captures WHERE id = :id', ['id' => (int) $pdo->lastInsertId()]) ?? [];
    }

    public function saveHotspot(int $sceneId, array $input): array
    {
        if (!$this->sceneReference($sceneId)) {
            throw new RuntimeException('Spatial сцену не знайдено.');
        }
        $id = (int) ($input['id'] ?? 0);
        $title = mb_substr(trim((string) ($input['title'] ?? '')), 0, 180);
        if ($title === '') {
            throw new RuntimeException('Вкажіть назву hotspot.');
        }
        $position = $this->position($input);
        $values = [
            'scene_id' => $sceneId,
            'version_id' => (int) ($input['version_id'] ?? 0) ?: null,
            'hotspot_type' => $this->allowed((string) ($input['hotspot_type'] ?? ''), self::HOTSPOT_TYPES, 'info'),
            'title' => $title,
            'body' => $this->nullable((string) ($input['body'] ?? ''), 5000),
            'icon' => $this->nullable((string) ($input['icon'] ?? ''), 60),
            'position_json' => json_encode($position, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
            'target_json' => $this->jsonObject($input['target_json'] ?? null),
            'action_url' => $this->safeUrlOrNull((string) ($input['action_url'] ?? '')),
            'sort_order' => max(1, min(10000, (int) ($input['sort_order'] ?? 100))),
            'is_active' => isset($input['is_active']) ? (int) (bool) $input['is_active'] : 1,
        ];
        $pdo = $this->database->connection();
        if ($id > 0) {
            $values['id'] = $id;
            $pdo->prepare('
                UPDATE tn_spatial_hotspots SET version_id = :version_id, hotspot_type = :hotspot_type,
                    title = :title, body = :body, icon = :icon, position_json = :position_json,
                    target_json = :target_json, action_url = :action_url, sort_order = :sort_order, is_active = :is_active
                WHERE id = :id AND scene_id = :scene_id LIMIT 1
            ')->execute($values);
        } else {
            $values['public_id'] = $this->publicId('SPH');
            $pdo->prepare('
                INSERT INTO tn_spatial_hotspots (
                    public_id, scene_id, version_id, hotspot_type, title, body, icon, position_json,
                    target_json, action_url, sort_order, is_active
                ) VALUES (
                    :public_id, :scene_id, :version_id, :hotspot_type, :title, :body, :icon, :position_json,
                    :target_json, :action_url, :sort_order, :is_active
                )
            ')->execute($values);
            $id = (int) $pdo->lastInsertId();
        }
        return $this->database->fetchOne('SELECT * FROM tn_spatial_hotspots WHERE id = :id LIMIT 1', ['id' => $id]) ?? [];
    }

    public function publish(int $sceneId): array
    {
        $scene = $this->scene($sceneId);
        if (!$scene) {
            return ['ok' => false, 'message' => 'Spatial сцену не знайдено.'];
        }
        $ready = match ((string) $scene['viewer_type']) {
            'external' => !empty($scene['external_url']),
            'panorama' => $this->hasReadyAsset($scene['assets'], 'panorama'),
            'gaussian_splat' => $this->hasReadyAsset($scene['assets'], 'gaussian_splat'),
            default => $this->hasReadyAsset($scene['assets'], 'model_web'),
        };
        if (!$ready) {
            return ['ok' => false, 'message' => 'Немає готового основного asset для вибраного viewer.'];
        }
        $pdo = $this->database->connection();
        $pdo->prepare('UPDATE tn_spatial_scenes SET status = "published", published_at = COALESCE(published_at, NOW()) WHERE id = :id')
            ->execute(['id' => $sceneId]);
        if (!empty($scene['property_id'])) {
            $pdo->prepare('UPDATE tn_properties SET has_3d_tour = 1, tour_url = :url WHERE id = :id')
                ->execute(['url' => '/spatial/scene/' . $scene['slug'], 'id' => $scene['property_id']]);
        }
        return ['ok' => true, 'message' => 'Spatial сцену опубліковано.', 'slug' => $scene['slug']];
    }

    public function recordEvent(string $reference, array $input, ?array $user = null): bool
    {
        $event = $this->allowed((string) ($input['event_type'] ?? ''), self::EVENT_TYPES, 'view');
        $scene = $this->database->fetchOne('SELECT id FROM tn_spatial_scenes WHERE (public_id = :ref OR slug = :ref) AND status = "published" LIMIT 1', ['ref' => $reference]);
        if (!$scene) {
            return false;
        }
        $this->database->connection()->prepare('
            INSERT INTO tn_spatial_events (scene_id, event_type, user_id, session_key, source_page, payload_json)
            VALUES (:scene_id, :event_type, :user_id, :session_key, :source_page, :payload_json)
        ')->execute([
            'scene_id' => $scene['id'],
            'event_type' => $event,
            'user_id' => !empty($user['id']) ? (int) $user['id'] : null,
            'session_key' => $this->nullable((string) ($input['session_key'] ?? ''), 120),
            'source_page' => $this->nullable((string) ($input['source_page'] ?? ''), 500),
            'payload_json' => $this->jsonObject($input['payload'] ?? null),
        ]);
        return true;
    }

    private function details(array $scene, bool $public): array
    {
        $assetWhere = $public ? 'AND a.status = "ready"' : 'AND a.status <> "archived"';
        $scene['assets'] = $this->database->fetchAll('
            SELECT a.*, m.original_name, m.mime_type
            FROM tn_spatial_assets a LEFT JOIN tn_media_assets m ON m.id = a.media_id
            WHERE a.scene_id = :scene_id ' . $assetWhere . '
            ORDER BY a.is_primary DESC, a.sort_order, a.id
        ', ['scene_id' => $scene['id']]);
        $scene['hotspots'] = $this->database->fetchAll('
            SELECT * FROM tn_spatial_hotspots WHERE scene_id = :scene_id ' . ($public ? 'AND is_active = 1' : '') . '
            ORDER BY sort_order, id
        ', ['scene_id' => $scene['id']]);
        $scene['versions'] = $this->database->fetchAll('SELECT * FROM tn_spatial_versions WHERE scene_id = :scene_id ORDER BY version_number DESC', ['scene_id' => $scene['id']]);
        if (!$public) {
            $scene['captures'] = $this->database->fetchAll('SELECT * FROM tn_spatial_captures WHERE scene_id = :scene_id ORDER BY id DESC LIMIT 50', ['scene_id' => $scene['id']]);
            $scene['jobs'] = $this->database->fetchAll('SELECT * FROM tn_spatial_processing_jobs WHERE scene_id = :scene_id ORDER BY id DESC LIMIT 50', ['scene_id' => $scene['id']]);
        }
        $scene['default_camera'] = $this->decoded((string) ($scene['default_camera_json'] ?? ''));
        $scene['settings'] = $this->decoded((string) ($scene['settings_json'] ?? ''));
        foreach ($scene['hotspots'] as &$hotspot) {
            $hotspot['position'] = $this->decoded((string) $hotspot['position_json']);
            $hotspot['target'] = $this->decoded((string) ($hotspot['target_json'] ?? ''));
        }
        unset($hotspot);
        if ($public) {
            $scene['viewer'] = $this->viewerPayload($scene);
            unset($scene['created_by_user_id'], $scene['default_camera_json'], $scene['settings_json']);
        }
        return $scene;
    }

    private function viewerPayload(array $scene): array
    {
        $find = static function (array $assets, string $type): ?array {
            foreach ($assets as $asset) {
                if ((string) $asset['asset_type'] === $type && (int) $asset['is_primary'] === 1) {
                    return $asset;
                }
            }
            foreach ($assets as $asset) {
                if ((string) $asset['asset_type'] === $type) {
                    return $asset;
                }
            }
            return null;
        };
        return [
            'type' => (string) $scene['viewer_type'],
            'model_url' => $find($scene['assets'], 'model_web')['uri'] ?? null,
            'ar_url' => $find($scene['assets'], 'model_ar')['uri'] ?? null,
            'panorama_url' => $find($scene['assets'], 'panorama')['uri'] ?? null,
            'splat_url' => $find($scene['assets'], 'gaussian_splat')['uri'] ?? null,
            'external_url' => $scene['external_url'] ?: null,
            'poster_url' => $scene['poster_url'] ?? ($find($scene['assets'], 'poster')['uri'] ?? null),
            'camera' => $scene['default_camera'],
            'settings' => $scene['settings'],
        ];
    }

    private function createVersion(PDO $pdo, int $sceneId, array $input, array $user): void
    {
        $method = $this->allowed((string) ($input['capture_method'] ?? ''), self::CAPTURE_METHODS, 'manual');
        $pdo->prepare('
            INSERT INTO tn_spatial_versions (
                scene_id, version_number, label, capture_method, status, device_name, captured_at, notes, created_by_user_id
            ) VALUES (:scene_id, 1, :label, :method, "draft", :device, :captured_at, :notes, :user_id)
        ')->execute([
            'scene_id' => $sceneId,
            'label' => $this->nullable((string) ($input['version_label'] ?? 'Початкова версія'), 160),
            'method' => $method,
            'device' => $this->nullable((string) ($input['device_name'] ?? ''), 160),
            'captured_at' => $this->dateTime($input['captured_at'] ?? null),
            'notes' => null,
            'user_id' => (int) $user['id'],
        ]);
    }

    private function syncPropertyRelation(PDO $pdo, int $sceneId, int $propertyId): void
    {
        $pdo->prepare('DELETE FROM tn_spatial_relations WHERE scene_id = :scene_id AND entity_type = "property" AND role = "primary"')
            ->execute(['scene_id' => $sceneId]);
        if ($propertyId > 0 && $this->database->fetchOne('SELECT id FROM tn_properties WHERE id = :id LIMIT 1', ['id' => $propertyId])) {
            $pdo->prepare('INSERT INTO tn_spatial_relations (scene_id, entity_type, entity_id, role, sort_order) VALUES (:scene_id, "property", :entity_id, "primary", 10)')
                ->execute(['scene_id' => $sceneId, 'entity_id' => $propertyId]);
        }
    }

    private function sceneForUpdate(PDO $pdo, int $id): ?array
    {
        $statement = $pdo->prepare('SELECT * FROM tn_spatial_scenes WHERE id = :id LIMIT 1 FOR UPDATE');
        $statement->execute(['id' => $id]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    private function uniqueSlug(PDO $pdo, string $value, int $ignoreId): string
    {
        $base = $this->slug($value) ?: 'spatial-scene';
        $slug = $base;
        $counter = 2;
        $statement = $pdo->prepare('SELECT id FROM tn_spatial_scenes WHERE slug = :slug AND id <> :id LIMIT 1');
        while (true) {
            $statement->execute(['slug' => $slug, 'id' => $ignoreId]);
            if (!$statement->fetchColumn()) {
                return $slug;
            }
            $slug = $base . '-' . $counter++;
        }
    }

    private function slug(string $value): string
    {
        $value = mb_strtolower(trim($value));
        $value = strtr($value, [
            'а'=>'a','б'=>'b','в'=>'v','г'=>'h','ґ'=>'g','д'=>'d','е'=>'e','є'=>'ye','ж'=>'zh','з'=>'z','и'=>'y','і'=>'i','ї'=>'yi','й'=>'y',
            'к'=>'k','л'=>'l','м'=>'m','н'=>'n','о'=>'o','п'=>'p','р'=>'r','с'=>'s','т'=>'t','у'=>'u','ф'=>'f','х'=>'kh','ц'=>'ts','ч'=>'ch',
            'ш'=>'sh','щ'=>'shch','ь'=>'','ю'=>'yu','я'=>'ya',
        ]);
        return trim(preg_replace('~[^a-z0-9]+~', '-', $value) ?: '', '-');
    }

    private function position(array $input): array
    {
        if (!empty($input['position_json'])) {
            $decoded = $this->decoded((string) $input['position_json']);
            if (isset($decoded['x'], $decoded['y'], $decoded['z'])) {
                return ['x' => (float) $decoded['x'], 'y' => (float) $decoded['y'], 'z' => (float) $decoded['z']];
            }
        }
        return ['x' => (float) ($input['position_x'] ?? 0), 'y' => (float) ($input['position_y'] ?? 0), 'z' => (float) ($input['position_z'] ?? 0)];
    }

    private function hasReadyAsset(array $assets, string $type): bool
    {
        foreach ($assets as $asset) {
            if (($asset['asset_type'] ?? '') === $type && ($asset['status'] ?? '') === 'ready') {
                return true;
            }
        }
        return false;
    }

    private function jsonObject(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        $decoded = is_array($value) ? $value : json_decode((string) $value, true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($decoded) || array_is_list($decoded)) {
            throw new RuntimeException('JSON value must be an object.');
        }
        return json_encode($decoded, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }

    private function decoded(string $value): array
    {
        $decoded = $value !== '' ? json_decode($value, true) : [];
        return is_array($decoded) ? $decoded : [];
    }

    private function safeUrlOrNull(string $url): ?string
    {
        $url = trim($url);
        if ($url === '') {
            return null;
        }
        return filter_var($url, FILTER_VALIDATE_URL) !== false && preg_match('~^https?://~i', $url) === 1
            ? mb_substr($url, 0, 700) : null;
    }

    private function dateTime(mixed $value): ?string
    {
        $value = trim((string) $value);
        $timestamp = $value !== '' ? strtotime($value) : false;
        return $timestamp !== false ? date('Y-m-d H:i:s', $timestamp) : null;
    }

    private function nullable(string $value, int $limit): ?string
    {
        $value = mb_substr(trim($value), 0, $limit);
        return $value !== '' ? $value : null;
    }

    private function allowed(string $value, array $allowed, string $fallback): string
    {
        return in_array($value, $allowed, true) ? $value : $fallback;
    }

    private function publicId(string $prefix): string
    {
        return $prefix . '-' . date('YmdHis') . '-' . strtoupper(bin2hex(random_bytes(5)));
    }

    private function logError(string $label, Throwable $error): void
    {
        $directory = BASE_PATH . '/tmp/logs';
        if (!is_dir($directory)) {
            @mkdir($directory, 0775, true);
        }
        @file_put_contents($directory . '/spatial.log', sprintf("[%s] %s: %s\n", date('Y-m-d H:i:s'), $label, $error->getMessage()), FILE_APPEND);
    }
}
