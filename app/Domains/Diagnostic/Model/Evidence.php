<?php
declare(strict_types=1);

namespace Domains\Diagnostic\Model;

use DateTimeImmutable;
use InvalidArgumentException;

final readonly class Evidence
{
    public function __construct(
        public string $id,
        public EvidenceType $type,
        public string $title,
        public string $source,
        public DateTimeImmutable $capturedAt,
        public array $metadata = [],
        public ?string $sourceReference = null,
        public string $collectionMethod = 'unknown',
        public float $reliability = 1.0,
        public float $directness = 1.0,
        public ?string $scope = null,
        public ?int $sampleSize = null,
        public mixed $rawValue = null,
    ) {
        if (trim($id) === '' || trim($title) === '' || trim($source) === '') {
            throw new InvalidArgumentException('Evidence id, title and source must not be empty.');
        }
        if ($reliability < 0 || $reliability > 1 || $directness < 0 || $directness > 1 || ($sampleSize !== null && $sampleSize < 1)) {
            throw new InvalidArgumentException('Evidence reliability/directness must be within 0..1 and sample size positive.');
        }
    }
}
