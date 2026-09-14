<?php
declare(strict_types=1);

namespace Domains\Sales\Application\DTO;

use DateTimeImmutable;

final readonly class CreateTaskCommand
{
    public function __construct(
        public string $organizationId,
        public string $dealReference,
        public string $title,
        public ?string $body,
        public ?DateTimeImmutable $dueAt,
        public string $idempotencyKey,
    ) {
    }
}
