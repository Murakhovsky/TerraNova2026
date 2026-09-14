<?php
declare(strict_types=1);

namespace Kernel\Audit;

use DateTimeImmutable;

final readonly class AuditEntry
{
    public function __construct(
        public string $id,
        public string $organizationId,
        public string $category,
        public string $actorType,
        public string $actorId,
        public string $subjectType,
        public string $subjectId,
        public ?string $reason,
        public array $data,
        public string $correlationId,
        public DateTimeImmutable $createdAt,
    ) {
    }
}
