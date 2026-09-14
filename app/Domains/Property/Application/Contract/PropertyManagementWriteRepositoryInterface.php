<?php
declare(strict_types=1);

namespace Domains\Property\Application\Contract;

interface PropertyManagementWriteRepositoryInterface
{
    public function createDraft(array $input, ?int $userId = null, array $files = []): array;
    public function updateStatus(int $propertyId, string $status, string $note = '', ?int $userId = null): array;
    public function updateDetails(int $propertyId, array $input, ?int $userId = null): array;
    public function update(int $propertyId, array $input, array $files, ?int $userId = null): array;
    public function addActivityNote(int $propertyId, array $input, ?int $userId = null): array;
}
