<?php
declare(strict_types=1);

namespace Domains\Sales\Application\DTO;

final readonly class RecordCompletedCallCommand
{
    public function __construct(
        public string $organizationId,
        public string $dealReference,
        public ?string $personReference,
        public ?string $userReference,
        public string $title,
        public ?string $body,
        public int $durationSeconds,
        public string $result,
        public string $eventId,
        public string $correlationId,
        public string $actorType,
        public string $actorId,
    ) {
    }
}
