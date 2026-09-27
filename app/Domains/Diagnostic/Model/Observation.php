<?php
declare(strict_types=1);

namespace Domains\Diagnostic\Model;

use DateTimeImmutable;
use InvalidArgumentException;

final readonly class Observation
{
    public function __construct(
        public string $id,
        public string $sourceKind,
        public string $statement,
        public DateTimeImmutable $observedAt,
        public array $metadata = [],
    ) {
        if (trim($id) === '' || trim($sourceKind) === '' || trim($statement) === '') {
            throw new InvalidArgumentException('Observation id, source kind and statement are required.');
        }
    }
}
