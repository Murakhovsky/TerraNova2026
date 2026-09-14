<?php
declare(strict_types=1);

namespace Domains\Property\Model;

use InvalidArgumentException;

final readonly class ResidentialSpec
{
    public function __construct(
        public ?float $totalArea = null,
        public ?float $livingArea = null,
        public ?float $rooms = null,
        public ?int $bedrooms = null,
        public ?int $bathrooms = null,
    ) {
        foreach ([$this->totalArea, $this->livingArea, $this->rooms] as $value) {
            if ($value !== null && $value < 0) {
                throw new InvalidArgumentException('ResidentialSpec numeric values cannot be negative.');
            }
        }
        if ($this->totalArea !== null && $this->livingArea !== null && $this->livingArea > $this->totalArea) {
            throw new InvalidArgumentException('ResidentialSpec living area cannot exceed total area.');
        }
        if (($this->bedrooms !== null && $this->bedrooms < 0) || ($this->bathrooms !== null && $this->bathrooms < 0)) {
            throw new InvalidArgumentException('ResidentialSpec room counters cannot be negative.');
        }
    }
}
