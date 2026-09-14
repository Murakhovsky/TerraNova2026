<?php
declare(strict_types=1);

namespace Domains\Property\Model;

use InvalidArgumentException;

final readonly class CommercialSpec
{
    public function __construct(
        public ?float $totalArea = null,
        public ?float $usableArea = null,
        public ?float $ceilingHeight = null,
        public ?int $entrances = null,
    ) {
        foreach ([$this->totalArea, $this->usableArea, $this->ceilingHeight] as $value) {
            if ($value !== null && $value < 0) {
                throw new InvalidArgumentException('CommercialSpec numeric values cannot be negative.');
            }
        }
        if ($this->totalArea !== null && $this->usableArea !== null && $this->usableArea > $this->totalArea) {
            throw new InvalidArgumentException('CommercialSpec usable area cannot exceed total area.');
        }
        if ($this->entrances !== null && $this->entrances < 0) {
            throw new InvalidArgumentException('CommercialSpec entrance count cannot be negative.');
        }
    }
}
