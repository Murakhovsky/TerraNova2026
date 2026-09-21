<?php

declare(strict_types=1);

namespace Infrastructure\Media;

use RuntimeException;

final readonly class UploadQuarantineService
{
    public function __construct(
        private string $rootDirectory,
    ) {
    }

    public function quarantine(array $file, int $maxBytes): QuarantinedUpload
    {
        $error = (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE);
        if ($error !== UPLOAD_ERR_OK) {
            throw new RuntimeException('Upload was not completed successfully.');
        }

        $source = (string) ($file['tmp_name'] ?? '');
        $size = (int) ($file['size'] ?? 0);

        if ($source === '' || !is_file($source)) {
            throw new RuntimeException('Upload temporary file is missing.');
        }

        if ($size <= 0 || $size > $maxBytes) {
            throw new RuntimeException('Upload size is outside the allowed limit.');
        }

        if (!is_dir($this->rootDirectory) && !mkdir($this->rootDirectory, 0700, true) && !is_dir($this->rootDirectory)) {
            throw new RuntimeException('Upload quarantine directory is unavailable.');
        }

        $quarantinePath = rtrim($this->rootDirectory, '/\\')
            . DIRECTORY_SEPARATOR
            . bin2hex(random_bytes(20))
            . '.upload';

        $stored = is_uploaded_file($source)
            ? move_uploaded_file($source, $quarantinePath)
            : copy($source, $quarantinePath);

        if (!$stored) {
            throw new RuntimeException('Upload could not be moved into quarantine.');
        }

        @chmod($quarantinePath, 0600);

        $mime = 'application/octet-stream';
        if (function_exists('finfo_open') && defined('FILEINFO_MIME_TYPE')) {
            $info = finfo_open(FILEINFO_MIME_TYPE);
            if ($info) {
                $detected = (string) finfo_file($info, $quarantinePath);
                finfo_close($info);
                if ($detected !== '') {
                    $mime = $detected;
                }
            }
        }

        return new QuarantinedUpload(
            path: $quarantinePath,
            originalName: basename(str_replace('\\', '/', (string) ($file['name'] ?? 'upload'))),
            mimeType: $mime,
            sizeBytes: (int) (filesize($quarantinePath) ?: $size),
            checksum: (string) (hash_file('sha256', $quarantinePath) ?: ''),
        );
    }
}
