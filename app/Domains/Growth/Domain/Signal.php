<?php
declare(strict_types=1);

namespace Domains\Growth\Domain;

use DateTimeImmutable;
use InvalidArgumentException;
use Kernel\Shared\Domain\OrganizationId;

final readonly class Signal
{
    /**
     * @param array<string, scalar|null> $facts
     */
    public function __construct(
        public string $id,
        public OrganizationId $organizationId,
        public string $subjectType,
        public string $subjectId,
        public string $signalType,
        public array $facts,
        public string $sourceReference,
        public float $confidence,
        public DateTimeImmutable $occurredAt,
        public DateTimeImmutable $detectedAt,
    ) {
        foreach ([
            'id' => $id,
            'subjectType' => $subjectType,
            'subjectId' => $subjectId,
            'signalType' => $signalType,
            'sourceReference' => $sourceReference,
        ] as $field => $value) {
            if (trim($value) === '') {
                throw new InvalidArgumentException(sprintf('Growth Signal %s is required.', $field));
            }
        }

        if ($facts === []) {
            throw new InvalidArgumentException('Growth Signal must contain observable facts.');
        }

        if ($confidence < 0.0 || $confidence > 1.0) {
            throw new InvalidArgumentException('Growth Signal confidence must be between 0 and 1.');
        }

        if ($detectedAt < $occurredAt) {
            throw new InvalidArgumentException('Growth Signal cannot be detected before it occurred.');
        }
    }
}
