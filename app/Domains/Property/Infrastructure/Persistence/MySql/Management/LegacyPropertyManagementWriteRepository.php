<?php
declare(strict_types=1);

namespace Domains\Property\Infrastructure\Persistence\MySql\Management;

use Domains\Property\Application\Contract\PropertyManagementWriteRepositoryInterface;
use Domains\Property\Infrastructure\Persistence\MySql\MysqlPropertyManagementRepository;

final readonly class LegacyPropertyManagementWriteRepository implements PropertyManagementWriteRepositoryInterface
{
    public function __construct(private MysqlPropertyManagementRepository $backend) {}

    public function createDraft(array $input, ?int $userId = null, array $files = []): array { return $this->backend->createDraft($input, $userId, $files); }
    public function updateStatus(int $propertyId, string $status, string $note = '', ?int $userId = null): array { return $this->backend->updateStatus($propertyId, $status, $note, $userId); }
    public function updateDetails(int $propertyId, array $input, ?int $userId = null): array { return $this->backend->updateDetails($propertyId, $input, $userId); }
    public function update(int $propertyId, array $input, array $files, ?int $userId = null): array { return $this->backend->update($propertyId, $input, $files, $userId); }
    public function addActivityNote(int $propertyId, array $input, ?int $userId = null): array { return $this->backend->addActivityNote($propertyId, $input, $userId); }
}
