<?php
declare(strict_types=1);

namespace Common\Services;

use RuntimeException;

class MediaStorageService
{
    private const MAX_IMAGE_BYTES = 10485760;
    private const MAX_MAIN_IMAGE_BYTES = 5242880;

    private const MIME_EXTENSIONS = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/webp' => 'webp',
        'image/gif' => 'gif',
        'video/mp4' => 'mp4',
        'application/pdf' => 'pdf',
    ];

    public function __construct(private DatabaseService $database)
    {
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
            throw new RuntimeException('Upload failed with code ' . (int) $file['error']);
        }

        $tmpPath = (string) ($file['tmp_name'] ?? '');
        $size = (int) ($file['size'] ?? 0);

        if ($tmpPath === '' || !is_file($tmpPath)) {
            throw new RuntimeException('Uploaded file is not available.');
        }

        if ($size <= 0 || $size > $maxBytes) {
            throw new RuntimeException('Uploaded file size is not allowed.');
        }

        $mime = $this->detectMime($tmpPath);
        if (!isset(self::MIME_EXTENSIONS[$mime])) {
            throw new RuntimeException('Uploaded file type is not allowed: ' . $mime);
        }

        $extension = self::MIME_EXTENSIONS[$mime];
        $kind = $this->kindFromMime($mime);
        $publicId = $this->publicId();
        $relativeDir = 'uploads/media/' . $this->safeSegment($entityType) . '/' . $entityId . '/' . date('Ymd');
        $absoluteDir = BASE_PATH . '/public/' . $relativeDir;

        if (!is_dir($absoluteDir) && !mkdir($absoluteDir, 0775, true) && !is_dir($absoluteDir)) {
            throw new RuntimeException('Media directory cannot be created.');
        }

        $fileName = $publicId . '.' . $extension;
        $relativePath = $relativeDir . '/' . $fileName;
        $absolutePath = BASE_PATH . '/public/' . $relativePath;

        if (is_uploaded_file($tmpPath)) {
            $moved = move_uploaded_file($tmpPath, $absolutePath);
        } else {
            $moved = copy($tmpPath, $absolutePath);
        }

        if (!$moved) {
            throw new RuntimeException('Uploaded file cannot be stored.');
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

    private function detectMime(string $path): string
    {
        if (function_exists('finfo_open')) {
            $info = finfo_open(FILEINFO_MIME_TYPE);
            $mime = $info ? (string) finfo_file($info, $path) : '';

            if ($info) {
                finfo_close($info);
            }

            if ($mime !== '') {
                return $mime;
            }
        }

        $imageInfo = @getimagesize($path);
        if (is_array($imageInfo) && !empty($imageInfo['mime'])) {
            return (string) $imageInfo['mime'];
        }

        if (function_exists('mime_content_type')) {
            $mime = (string) @mime_content_type($path);

            if ($mime !== '') {
                return $mime;
            }
        }

        return 'application/octet-stream';
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
