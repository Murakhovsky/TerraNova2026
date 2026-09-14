<?php
declare(strict_types=1);

namespace Domains\Property\Application\Contract;

interface PropertySubmissionMediaInterface
{
    public function storeUploadedFiles(array $files, string $entityType, int $entityId): array;
}
