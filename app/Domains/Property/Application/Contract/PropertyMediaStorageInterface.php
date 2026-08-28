<?php
declare(strict_types=1);

namespace Domains\Property\Application\Contract;

interface PropertyMediaStorageInterface
{
    public function storeUploadedFiles(array $files, string $entityType, int $entityId): array;

    public function assetsFor(string $entityType, int $entityId): array;

    public function relateExistingMedia(int $mediaId, string $entityType, int $entityId, string $role, int $sortOrder): void;

    public function markDeletedByPublicUrls(string $entityType, int $entityId, array $publicUrls): void;

    public function syncRelationMetadata(string $entityType, int $entityId, array $items): void;
}
