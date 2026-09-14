<?php
declare(strict_types=1);
namespace Domains\Sales\Application\DTO;

use DateTimeImmutable;
use Domains\Sales\Model\OutcomeAttribution;

final readonly class RecordActionOutcomeCommand
{
    public function __construct(
        public string $organizationId,
        public string $actionId,
        public string $metric,
        public mixed $value,
        public OutcomeAttribution $attribution,
        public array $evidence,
        public DateTimeImmutable $measuredAt,
        public string $correlationId,
        public string $actorType,
        public string $actorId,
    ) {}
}
