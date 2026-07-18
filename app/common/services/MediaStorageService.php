<?php
declare(strict_types=1);

namespace Common\Services;

use RuntimeException;

class MediaStorageService
{
    private const MAX_IMAGE_BYTES = 52428800;
    private const MAX_MAIN_IMAGE_BYTES = 52428800;

    private const MIME_EXTENSIONS = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/webp' => 'webp',
        'image/gif' => 'gif',
        'video/mp4' => 'mp4',
        'application/pdf' => 'pdf',
    ];

    public function __construct(private DatabaseService $database, private ?ImageOptimizerService $imageOptimizer = null)
    {
        $this->imageOptimizer ??= new ImageOptimizerService();
    }

    public function storeUploadedFiles(array $files, string $entityType, int $entityId): array
    {
        if ($entityId <= 0) {
            throw new RuntimeException('Media entity id is required.');
        }

        $stored = [];
        $stored = array_merge($stored, $this->storeField($files['main_photo'] ?? null, $entityType, $entityId, 'cover', self::MAX_MAIN_IMAGE_BYTES, 10));
        $stored = array_merge($stored, $this->storeField($files['gallery_photos'] ?? null, $entityType, $entityId, 'gallery', self::MAX_IMAGE_BYTES, 100));

        return $stored;
    }

    public function assetsFor(string $entityType, int $entityId): array
    {
        return $this->database->fetchAll('
            SELECT a.*, r.role, r.sort_order
            FROM tn_media_relations r
            INNER JOIN tn_media_assets a ON a.id = r.media_id
            WHERE r.entity_type = :entity_type AND r.entity_id = :entity_id AND a.status <> "deleted"
            ORDER BY r.role = "cover" DESC, r.sort_order, a.id
        ', [
            'entity_type' => $entityType,
            'entity_id' => $entityId,
        ]);
    }

    public function relateExistingMedia(int $mediaId, string $entityType, int $entityId, string $role, int $sortOrder): void
    {
        $statement = $this->database->connection()->prepare('
            INSERT IGNORE INTO tn_media_relations (media_id, entity_type, entity_id, role, sort_order)
            VALUES (:media_id, :entity_type, :entity_id, :role, :sort_order)
        ');
        $statement->execute([
            'media_id' => $mediaId,
            'entity_type' => $entityType,
            'entity_id' => $entityId,
            'role' => $role,
            'sort_order' => $sortOrder,
        ]);
    }

    public function markDeletedByPublicUrls(string $entityType, int $entityId, array $publicUrls): void
    {
        $publicUrls = array_values(array_unique(array_filter(array_map(static function (mixed $url): string {
            return trim((string) $url);
        }, $publicUrls))));

        if ($entityId <= 0 || !$publicUrls) {
            return;
        }

        $pdo = $this->database->connection();
        $placeholders = implode(',', array_fill(0, count($publicUrls), '?'));
        $params = array_merge([$entityType, $entityId], $publicUrls);

        $select = $pdo->prepare('
            SELECT a.id
            FROM tn_media_assets a
            INNER JOIN tn_media_relations r ON r.media_id = a.id
            WHERE r.entity_type = ?
              AND r.entity_id = ?
              AND a.public_url IN (' . $placeholders . ')
        ');
        $select->execute($params);
        $assetIds = array_map('intval', $select->fetchAll(\PDO::FETCH_COLUMN));

        if (!$assetIds) {
            return;
        }

        $assetPlaceholders = implode(',', array_fill(0, count($assetIds), '?'));
        $relation = $pdo->prepare('
            DELETE FROM tn_media_relations
            WHERE entity_type = ?
              AND entity_id = ?
              AND media_id IN (' . $assetPlaceholders . ')
        ');
        $relation->execute(array_merge([$entityType, $entityId], $assetIds));

        $assets = $pdo->prepare('
            UPDATE tn_media_assets
            SET status = "deleted"
            WHERE id IN (' . $assetPlaceholders . ')
        ');
        $assets->execute($assetIds);
    }

    private function storeField(?array $field, string $entityType, int $entityId, string $role, int $maxBytes, int $baseSort): array
    {
        if (!$field) {
            return [];
        }

        $items = $this->normalizeField($field);
        $stored = [];
        $sort = $baseSort;

        foreach ($items as $item) {
            if ((int) ($item['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
                continue;
            }

            $stored[] = $this->storeOne($item, $entityType, $entityId, $role, $sort, $maxBytes);
            $sort += 10;
        }

        return $stored;
    }

    private function normalizeField(array $field): array
    {
        if (!is_array($field['name'] ?? null)) {
            return [$field];
        }

        $items = [];
        foreach ($field['name'] as $index => $name) {
            $items[] = [
                'name' => $name,
                'type' => $field['type'][$index] ?? '',
                'tmp_name' => $field['tmp_name'][$index] ?? '',
                'error' => $field['error'][$index] ?? UPLOAD_ERR_NO_FILE,
                'size' => $field['size'][$index] ?? 0,
            ];
        }

        return $items;
    }

    private function storeOne(array $file, string $entityType, int $entityId, string $role, int $sortOrder, int $maxBytes): array
    {
        if ((int) ($file['error'] ?? UPLOAD_ERR_OK) !== UPLOAD_ERR_OK) {
            throw new RuntimeException($this->uploadErrorMessage((int) $file['error'], $maxBytes));
        }

        $tmpPath = (string) ($file['tmp_name'] ?? '');
        $size = (int) ($file['size'] ?? 0);

        if ($tmpPath === '' || !is_file($tmpPath)) {
            throw new RuntimeException('Файл не дійшов до сервера. Спробуйте ще раз або виберіть менший файл.');
        }

        if ($size <= 0 || $size > $maxBytes) {
            throw new RuntimeException('Файл завеликий. Максимум: ' . $this->bytesLabel($maxBytes) . '.');
        }

        $mime = $this->detectMime($tmpPath);
        if (!isset(self::MIME_EXTENSIONS[$mime])) {
            throw new RuntimeException('Тип файлу не підтримується: ' . $mime . '. Додайте JPG, PNG, WebP або GIF.');
        }

        $extension = self::MIME_EXTENSIONS[$mime];
        $kind = $this->kindFromMime($mime);
        $publicId = $this->publicId();
        $relativeDir = 'uploads/media/' . $this->safeSegment($entityType) . '/' . $entityId . '/' . date('Ymd');
        $absoluteDir = BASE_PATH . '/public/' . $relativeDir;

        if (!is_dir($absoluteDir) && !mkdir($absoluteDir, 0775, true) && !is_dir($absoluteDir)) {
            throw new RuntimeException('Не вдалося створити папку для медіа.');
        }

        $fileName = $publicId . '.' . $extension;
        $relativePath = $relativeDir . '/' . $fileName;
        $absolutePath = BASE_PATH . '/public/' . $relativePath;
        $incomingPath = $absolutePath . '.upload';

        if (is_uploaded_file($tmpPath)) {
            $moved = move_uploaded_file($tmpPath, $incomingPath);
        } else {
            $moved = copy($tmpPath, $incomingPath);
        }

        if (!$moved) {
            throw new RuntimeException('Не вдалося зберегти файл на диску.');
        }

        if ($kind === 'image') {
            $optimized = $this->optimizeStoredImage($incomingPath, $absolutePath, $mime);
            $absolutePath = $optimized['absolute_path'];
            $relativePath = $optimized['relative_path'];
            $mime = $optimized['mime'];
            $extension = $optimized['extension'];
        } else {
            if (!rename($incomingPath, $absolutePath) && !copy($incomingPath, $absolutePath)) {
                @unlink($incomingPath);

                throw new RuntimeException('Uploaded file cannot be stored.');
            }

            @unlink($incomingPath);
        }

        $checksum = hash_file('sha256', $absolutePath) ?: null;
        $assetId = $this->insertAsset([
            'public_id' => $publicId,
            'kind' => $kind,
            'original_name' => $this->limit($this->baseName((string) $file['name']), 255),
            'mime_type' => $mime,
            'extension' => $extension,
            'size_bytes' => filesize($absolutePath) ?: $size,
            'storage_path' => $relativePath,
            'public_url' => '/' . str_replace('\\', '/', $relativePath),
            'checksum' => $checksum,
        ]);

        $this->relateExistingMedia($assetId, $entityType, $entityId, $role, $sortOrder);

        return [
            'id' => $assetId,
            'public_id' => $publicId,
            'public_url' => '/' . str_replace('\\', '/', $relativePath),
            'role' => $role,
        ];
    }

    private function insertAsset(array $data): int
    {
        $statement = $this->database->connection()->prepare('
            INSERT INTO tn_media_assets (
                public_id, kind, storage, original_name, mime_type, extension,
                size_bytes, storage_path, public_url, checksum, status
            ) VALUES (
                :public_id, :kind, "local", :original_name, :mime_type, :extension,
                :size_bytes, :storage_path, :public_url, :checksum, "uploaded"
            )
        ');
        $statement->execute($data);

        return (int) $this->database->connection()->lastInsertId();
    }

    private function optimizeStoredImage(string $incomingPath, string $absolutePath, string $mime): array
    {
        $extension = self::MIME_EXTENSIONS[$mime] ?? 'jpg';
        $finalPath = $absolutePath;
        $finalMime = $mime;

        try {
            $result = $this->imageOptimizer?->optimize($incomingPath, $absolutePath, $mime);
            if ($result && $result->optimized && is_file($result->path)) {
                $finalPath = $result->path;
                $finalMime = $result->mime;
                $extension = $result->extension;
                @unlink($incomingPath);
            } elseif (!rename($incomingPath, $absolutePath) && !copy($incomingPath, $absolutePath)) {
                @unlink($incomingPath);

                throw new RuntimeException('Uploaded file cannot be stored.');
            }
        } catch (RuntimeException $e) {
            throw $e;
        } catch (\Throwable) {
            if (!is_file($absolutePath) && !rename($incomingPath, $absolutePath) && !copy($incomingPath, $absolutePath)) {
                @unlink($incomingPath);

                throw new RuntimeException('Uploaded file cannot be stored.');
            }
        }

        @unlink($incomingPath);

        return [
            'absolute_path' => $finalPath,
            'relative_path' => $this->relativePublicPath($finalPath),
            'mime' => $finalMime,
            'extension' => $extension,
        ];
    }

    private function relativePublicPath(string $absolutePath): string
    {
        $publicPath = str_replace('\\', '/', BASE_PATH . '/public/');
        $path = str_replace('\\', '/', $absolutePath);

        if (str_starts_with($path, $publicPath)) {
            return ltrim(substr($path, strlen($publicPath)), '/');
        }

        return ltrim(basename($absolutePath), '/');
    }

    private function detectMime(string $path): string
    {
        $imageInfo = @\getimagesize($path);
        if (is_array($imageInfo) && !empty($imageInfo['mime'])) {
            return (string) $imageInfo['mime'];
        }

        if (\function_exists('finfo_open') && \defined('FILEINFO_MIME_TYPE')) {
            $info = \finfo_open(\FILEINFO_MIME_TYPE);
            $mime = $info ? (string) \finfo_file($info, $path) : '';

            if ($info) {
                \finfo_close($info);
            }

            if ($mime !== '') {
                return $mime;
            }
        }

        if (\function_exists('mime_content_type')) {
            $mime = (string) @\mime_content_type($path);

            if ($mime !== '') {
                return $mime;
            }
        }

        return 'application/octet-stream';
    }

    private function uploadErrorMessage(int $error, int $maxBytes): string
    {
        return match ($error) {
            UPLOAD_ERR_INI_SIZE,
            UPLOAD_ERR_FORM_SIZE => 'Файл завеликий для поточних налаштувань сервера. Максимум у формі: ' . $this->bytesLabel($maxBytes) . '.',
            UPLOAD_ERR_PARTIAL => 'Файл завантажився не повністю. Спробуйте повторити.',
            UPLOAD_ERR_NO_TMP_DIR => 'На сервері не налаштована тимчасова папка для upload.',
            UPLOAD_ERR_CANT_WRITE => 'Сервер не зміг записати файл на диск.',
            UPLOAD_ERR_EXTENSION => 'PHP-розширення зупинило завантаження файлу.',
            default => 'Не вдалося завантажити файл. Код помилки: ' . $error . '.',
        };
    }

    private function bytesLabel(int $bytes): string
    {
        if ($bytes >= 1048576) {
            return rtrim(rtrim(number_format($bytes / 1048576, 1, '.', ''), '0'), '.') . ' МБ';
        }

        return (string) $bytes . ' Б';
    }

    private function kindFromMime(string $mime): string
    {
        if (str_starts_with($mime, 'image/')) {
            return 'image';
        }

        if (str_starts_with($mime, 'video/')) {
            return 'video';
        }

        if ($mime === 'application/pdf') {
            return 'document';
        }

        return 'other';
    }

    private function publicId(): string
    {
        return 'MDA-' . date('YmdHis') . '-' . strtoupper(bin2hex(random_bytes(4)));
    }

    private function safeSegment(string $value): string
    {
        $value = strtolower(trim($value));
        $value = preg_replace('~[^a-z0-9_]+~', '-', $value) ?: 'entity';

        return trim($value, '-');
    }

    private function baseName(string $name): string
    {
        return basename(str_replace('\\', '/', $name));
    }

    private function limit(string $value, int $limit): string
    {
        return mb_substr(trim($value), 0, $limit);
    }
}
