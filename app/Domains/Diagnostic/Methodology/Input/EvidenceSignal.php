<?php
declare(strict_types=1);

namespace Domains\Diagnostic\Methodology\Input;

use DateTimeImmutable;
use InvalidArgumentException;

final readonly class EvidenceSignal
{
    public function __construct(
        public string $id,
        public string $sourceType,
        public float $reliability,
        public float $quality = 1.0,
        public ?DateTimeImmutable $capturedAt = null,
        public mixed $claim = null,
    ) {
        if ($id === '' || $sourceType === '' || $reliability < 0 || $reliability > 1 || $quality < 0 || $quality > 1) {
            throw new InvalidArgumentException('Evidence signal requires identity and reliability/quality in the 0..1 range.');
        }
    }
}
