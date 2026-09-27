<?php
declare(strict_types=1);

namespace Domains\Diagnostic\Model;

use DateTimeImmutable;
use InvalidArgumentException;

final readonly class FactRevision
{
    /** @param list<string> $evidenceIds */
    public function __construct(
        public string $factId,
        public int $revision,
        public mixed $previousValue,
        public mixed $newValue,
        public string $reason,
        public string $source,
        public array $evidenceIds,
        public DateTimeImmutable $createdAt,
        public TruthLevel $truthLevel = TruthLevel::Reported,
        public float $confidence = 1.0,
        public ?int $supersedesRevision = null,
    ) {
        if ($factId === '' || $revision < 1 || trim($reason) === '' || trim($source) === ''
            || count(array_unique($evidenceIds)) !== count($evidenceIds)
            || $confidence < 0 || $confidence > 1
            || ($supersedesRevision !== null && ($supersedesRevision < 1 || $supersedesRevision >= $revision))) {
            throw new InvalidArgumentException('Invalid fact revision.');
        }
    }
}
