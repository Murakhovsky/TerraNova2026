<?php

declare(strict_types=1);

namespace App\Web\Experience\Extension\Model;

use App\Web\Experience\Model\EntityRef;
use DateTimeImmutable;

final readonly class ActivityItem
{
    public function __construct(
        public string $id,
        public string $label,
        public string $status,
        public ?string $path = null,
        public ?string $detail = null,
        public ?DateTimeImmutable $occurredAt = null,
        public ?int $progress = null,
        public ?string $correlationId = null,
        public ?EntityRef $entity = null,
        public bool $retryable = false,
    ) {
    }
}
