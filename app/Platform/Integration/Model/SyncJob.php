<?php
declare(strict_types=1);

namespace Platform\Integration\Model;

use DateTimeImmutable;
use InvalidArgumentException;
use Kernel\Shared\Domain\OrganizationId;

final readonly class SyncJob
{
    public function __construct(
        public string $id,
        public OrganizationId $organizationId,
        public string $connectionId,
        public SyncDirection $direction,
        public string $resourceType,
        public SyncJobStatus $status,
        public ?string $cursor,
        public int $processed,
        public int $succeeded,
        public int $failed,
        public ?string $error,
        public DateTimeImmutable $createdAt,
        public ?DateTimeImmutable $startedAt = null,
        public ?DateTimeImmutable $finishedAt = null,
    ) {
        if (trim($this->id) === '' || trim($this->connectionId) === '' || trim($this->resourceType) === '') {
            throw new InvalidArgumentException('Sync job requires id, connection and resource type.');
        }
        if (min($this->processed, $this->succeeded, $this->failed) < 0 || $this->succeeded + $this->failed > $this->processed) {
            throw new InvalidArgumentException('Sync counters are inconsistent.');
        }
    }
}
