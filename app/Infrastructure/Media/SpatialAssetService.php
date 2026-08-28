<?php
declare(strict_types=1);

namespace Infrastructure\Media;

use Domains\Spatial\Application\Contract\SpatialAssetStorageInterface;
use Infrastructure\Database\Connection\DatabaseService;
use RuntimeException;

class SpatialAssetService implements SpatialAssetStorageInterface
{
    private const FORMATS = [
        'glb' => ['asset_type' => 'model_web', 'mime' => 'model/gltf-binary', 'job' => 'validate'],
        'gltf' => ['asset_type' => 'model_web', 'mime' => 'model/gltf+json', 'job' => 'validate'],
        'usdz' => ['asset_type' => 'model_ar', 'mime' => 'model/vnd.usdz+zip', 'job' => 'validate'],
        'obj' => ['asset_type' => 'source', 'mime' => 'text/plain', 'job' => 'convert'],
        'fbx' => ['asset_type' => 'source', 'mime' => 'application/octet-stream', 'job' => 'convert'],
        'ply' => ['asset_type' => 'point_cloud', 'mime' => 'application/octet-stream', 'job' => 'inspect'],
        'splat' => ['asset_type' => 'gaussian_splat', 'mime' => 'application/octet-stream', 'job' => 'inspect'],
        'ksplat' => ['asset_type' => 'gaussian_splat', 'mime' => 'application/octet-stream', 'job' => 'inspect'],
        'spz' => ['asset_type' => 'gaussian_splat', 'mime' => 'application/octet-stream', 'job' => 'inspect'],
        'sog' => ['asset_type' => 'gaussian_splat', 'mime' => 'application/octet-stream', 'job' => 'inspect'],
        'zip' => ['asset_type' => 'source', 'mime' => 'application/zip', 'job' => 'inspect'],
        'dxf' => ['asset_type' => 'floorplan', 'mime' => 'image/vnd.dxf', 'job' => 'inspect'],
        'pdf' => ['asset_type' => 'floorplan', 'mime' => 'application/pdf', 'job' => null],
        'jpg' => ['asset_type' => 'poster', 'mime' => 'image/jpeg', 'job' => null],
        'jpeg' => ['asset_type' => 'poster', 'mime' => 'image/jpeg', 'job' => null],
        'png' => ['asset_type' => 'poster', 'mime' => 'image/png', 'job' => null],
        'webp' => ['asset_type' => 'poster', 'mime' => 'image/webp', 'job' => null],
        'mp4' => ['asset_type' => 'video', 'mime' => 'video/mp4', 'job' => null],
    ];

    private const ASSET_TYPES = ['source', 'model_web', 'model_ar', 'texture', 'point_cloud', 'gaussian_splat', 'panorama', 'floorplan', 'poster', 'video', 'other'];

    public function __construct(private DatabaseService $database, private int $maxUploadBytes = 209715200)
    {
    }

    public function store(array $scene, array $file, array $input, array $user): array
    {
        $error = (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE);
        if ($error !== UPLOAD_ERR_OK) {
            throw new RuntimeException($this->uploadError($error));
        }
        $size = (int) ($file['size'] ?? 0);
        $tmp = (string) ($file['tmp_name'] ?? '');
        if ($size <= 0 || $size > $this->maxUploadBytes || $tmp === '' || !is_file($tmp)) {
            throw new RuntimeException('3D-файл відсутній або перевищує дозволений розмір.');
        }
        $originalName = $this->baseName((string) ($file['name'] ?? 'asset'));
        $extension = mb_strtolower((string) pathinfo($originalName, PATHINFO_EXTENSION));
        if (!isset(self::FORMATS[$extension])) {
            throw new RuntimeException('Формат .' . ($extension ?: '?') . ' не підтримується Spatial pipeline.');
        }
        $spec = self::FORMATS[$extension];
        $assetType = in_array((string) ($input['asset_type'] ?? ''), self::ASSET_TYPES, true)
            ? (string) $input['asset_type']
            : $spec['asset_type'];
        if ($extension === 'ply' && (string) ($scene['viewer_type'] ?? '') === 'gaussian_splat' && empty($input['asset_type'])) {
            $assetType = 'gaussian_splat';
        }
        $this->verifySignature($tmp, $extension);

        $publicId = $this->publicId('SPA');
        $directory = 'uploads/spatial/' . $this->safeSegment((string) $scene['public_id']) . '/' . date('Ymd');
        $absoluteDirectory = BASE_PATH . '/public/' . $directory;
        if (!is_dir($absoluteDirectory) && !mkdir($absoluteDirectory, 0775, true) && !is_dir($absoluteDirectory)) {
            throw new RuntimeException('Не вдалося створити Spatial storage.');
        }
        $relativePath = $directory . '/' . $publicId . '.' . ($extension === 'jpeg' ? 'jpg' : $extension);
        $absolutePath = BASE_PATH . '/public/' . $relativePath;
        $stored = is_uploaded_file($tmp) ? move_uploaded_file($tmp, $absolutePath) : copy($tmp, $absolutePath);
        if (!$stored) {
            throw new RuntimeException('Не вдалося записати Spatial asset на диск.');
        }

        $pdo = $this->database->connection();
        $pdo->beginTransaction();
        try {
            $mime = $this->detectMime($absolutePath, (string) $spec['mime']);
            $kind = in_array($assetType, ['poster', 'panorama', 'texture'], true) ? 'image'
                : ($assetType === 'video' ? 'video' : ($assetType === 'floorplan' ? 'document' : 'model'));
            $mediaId = $this->insertMedia($publicId, $kind, $originalName, $mime, $extension, $relativePath, $absolutePath);
            $versionId = $this->versionId((int) $scene['id'], (int) ($input['version_id'] ?? 0));
            $ready = $spec['job'] === null;
            if (!empty($input['is_primary'])) {
                $pdo->prepare('UPDATE tn_spatial_assets SET is_primary = 0 WHERE scene_id = :scene_id AND asset_type = :asset_type')
                    ->execute(['scene_id' => $scene['id'], 'asset_type' => $assetType]);
            }
            $statement = $pdo->prepare('
                INSERT INTO tn_spatial_assets (
                    public_id, scene_id, version_id, media_id, asset_type, format, storage, uri,
                    status, is_primary, sort_order, size_bytes, checksum, metadata_json
                ) VALUES (
                    :public_id, :scene_id, :version_id, :media_id, :asset_type, :format, "local", :uri,
                    :status, :is_primary, :sort_order, :size_bytes, :checksum, :metadata_json
                )
            ');
            $statement->execute([
                'public_id' => $publicId,
                'scene_id' => $scene['id'],
                'version_id' => $versionId ?: null,
                'media_id' => $mediaId,
                'asset_type' => $assetType,
                'format' => $extension,
                'uri' => '/' . str_replace('\\', '/', $relativePath),
                'status' => $ready ? 'ready' : 'queued',
                'is_primary' => !empty($input['is_primary']) ? 1 : 0,
                'sort_order' => max(1, min(10000, (int) ($input['sort_order'] ?? 100))),
                'size_bytes' => filesize($absolutePath) ?: $size,
                'checksum' => hash_file('sha256', $absolutePath) ?: null,
                'metadata_json' => json_encode(['uploaded_by' => (int) $user['id']], JSON_UNESCAPED_SLASHES),
            ]);
            $assetId = (int) $pdo->lastInsertId();
            if ($assetType === 'poster') {
                $pdo->prepare('UPDATE tn_spatial_scenes SET poster_media_id = :media_id WHERE id = :scene_id')
                    ->execute(['media_id' => $mediaId, 'scene_id' => $scene['id']]);
            }
            if ($spec['job']) {
                $this->queue($assetId, (int) $scene['id'], (string) $spec['job']);
                $pdo->prepare('UPDATE tn_spatial_scenes SET status = "processing" WHERE id = :id AND status IN ("draft", "failed")')
                    ->execute(['id' => $scene['id']]);
            }
            $pdo->commit();
            return $this->asset($assetId) ?? [];
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            @unlink($absolutePath);
            throw $e;
        }
    }

    public function external(array $scene, array $input): array
    {
        $uri = trim((string) ($input['uri'] ?? ''));
        if (!$this->safeUrl($uri)) {
            throw new RuntimeException('Вкажіть коректний HTTPS URL зовнішнього asset.');
        }
        $assetType = in_array((string) ($input['asset_type'] ?? ''), self::ASSET_TYPES, true)
            ? (string) $input['asset_type'] : 'other';
        $format = mb_substr(mb_strtolower(trim((string) ($input['format'] ?? pathinfo((string) parse_url($uri, PHP_URL_PATH), PATHINFO_EXTENSION) ?: 'external'))), 0, 30);
        $pdo = $this->database->connection();
        if (!empty($input['is_primary'])) {
            $pdo->prepare('UPDATE tn_spatial_assets SET is_primary = 0 WHERE scene_id = :scene_id AND asset_type = :asset_type')
                ->execute(['scene_id' => $scene['id'], 'asset_type' => $assetType]);
        }
        $statement = $pdo->prepare('
            INSERT INTO tn_spatial_assets (
                public_id, scene_id, version_id, asset_type, format, storage, uri, status, is_primary, metadata_json
            ) VALUES (:public_id, :scene_id, :version_id, :asset_type, :format, "external", :uri, "ready", :is_primary, :metadata)
        ');
        $statement->execute([
            'public_id' => $this->publicId('SPA'),
            'scene_id' => $scene['id'],
            'version_id' => $this->versionId((int) $scene['id'], (int) ($input['version_id'] ?? 0)) ?: null,
            'asset_type' => $assetType,
            'format' => $format ?: 'external',
            'uri' => $uri,
            'is_primary' => !empty($input['is_primary']) ? 1 : 0,
            'metadata' => json_encode(['provider' => (string) ($input['provider'] ?? '')], JSON_UNESCAPED_SLASHES),
        ]);
        return $this->asset((int) $pdo->lastInsertId()) ?? [];
    }

    public function asset(int $id): ?array
    {
        return $this->database->fetchOne('SELECT * FROM tn_spatial_assets WHERE id = :id LIMIT 1', ['id' => $id]);
    }

    private function insertMedia(string $publicId, string $kind, string $name, string $mime, string $extension, string $relativePath, string $absolutePath): int
    {
        $statement = $this->database->connection()->prepare('
            INSERT INTO tn_media_assets (
                public_id, kind, storage, original_name, mime_type, extension, size_bytes,
                storage_path, public_url, checksum, status
            ) VALUES (
                :public_id, :kind, "local", :original_name, :mime_type, :extension, :size_bytes,
                :storage_path, :public_url, :checksum, "uploaded"
            )
        ');
        $statement->execute([
            'public_id' => 'MDA-' . substr($publicId, 4),
            'kind' => $kind,
            'original_name' => mb_substr($name, 0, 255),
            'mime_type' => mb_substr($mime, 0, 120),
            'extension' => mb_substr($extension, 0, 20),
            'size_bytes' => filesize($absolutePath) ?: 0,
            'storage_path' => $relativePath,
            'public_url' => '/' . str_replace('\\', '/', $relativePath),
            'checksum' => hash_file('sha256', $absolutePath) ?: null,
        ]);
        return (int) $this->database->connection()->lastInsertId();
    }

    private function queue(int $assetId, int $sceneId, string $jobType): void
    {
        $this->database->connection()->prepare('
            INSERT INTO tn_spatial_processing_jobs (public_id, scene_id, asset_id, job_type, input_json)
            VALUES (:public_id, :scene_id, :asset_id, :job_type, :input_json)
        ')->execute([
            'public_id' => $this->publicId('SPJ'),
            'scene_id' => $sceneId,
            'asset_id' => $assetId,
            'job_type' => $jobType,
            'input_json' => json_encode(['asset_id' => $assetId], JSON_UNESCAPED_SLASHES),
        ]);
    }

    private function versionId(int $sceneId, int $requested): int
    {
        if ($requested > 0) {
            return (int) ($this->database->fetchOne(
                'SELECT id FROM tn_spatial_versions WHERE id = :id AND scene_id = :scene_id LIMIT 1',
                ['id' => $requested, 'scene_id' => $sceneId]
            )['id'] ?? 0);
        }
        return (int) ($this->database->fetchOne(
            'SELECT id FROM tn_spatial_versions WHERE scene_id = :scene_id ORDER BY version_number DESC LIMIT 1',
            ['scene_id' => $sceneId]
        )['id'] ?? 0);
    }

    private function verifySignature(string $path, string $extension): void
    {
        $head = (string) file_get_contents($path, false, null, 0, 16);
        if ($extension === 'glb' && substr($head, 0, 4) !== 'glTF') {
            throw new RuntimeException('GLB файл не має коректного заголовка glTF.');
        }
        if (in_array($extension, ['usdz', 'zip'], true) && substr($head, 0, 2) !== 'PK') {
            throw new RuntimeException('USDZ/ZIP файл пошкоджений або має неправильний формат.');
        }
        if ($extension === 'gltf') {
            $json = json_decode((string) file_get_contents($path), true);
            if (!is_array($json) || !isset($json['asset']['version'])) {
                throw new RuntimeException('glTF JSON не містить опису asset.');
            }
        }
    }

    private function detectMime(string $path, string $fallback): string
    {
        $info = function_exists('finfo_open') ? finfo_open(FILEINFO_MIME_TYPE) : false;
        $mime = $info ? (string) finfo_file($info, $path) : '';
        return $mime !== '' && $mime !== 'application/octet-stream' ? $mime : $fallback;
    }

    private function uploadError(int $error): string
    {
        return match ($error) {
            UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => 'Файл перевищує серверний upload limit.',
            UPLOAD_ERR_PARTIAL => 'Файл завантажився не повністю.',
            UPLOAD_ERR_NO_FILE => 'Виберіть файл.',
            default => 'Помилка завантаження, код ' . $error . '.',
        };
    }

    private function publicId(string $prefix): string
    {
        return $prefix . '-' . date('YmdHis') . '-' . strtoupper(bin2hex(random_bytes(5)));
    }

    private function safeUrl(string $url): bool
    {
        return filter_var($url, FILTER_VALIDATE_URL) !== false && preg_match('~^https?://~i', $url) === 1;
    }

    private function safeSegment(string $value): string
    {
        return trim(preg_replace('~[^a-zA-Z0-9_-]+~', '-', $value) ?: 'scene', '-');
    }

    private function baseName(string $name): string
    {
        return basename(str_replace('\\', '/', $name));
    }
}
