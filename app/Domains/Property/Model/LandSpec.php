<?php
declare(strict_types=1);

namespace Domains\Property\Model;

use InvalidArgumentException;

final readonly class LandSpec
{
    public function __construct(
        public ?float $landArea = null,
        public ?float $buildableArea = null,
    ) {
        if ($this->landArea !== null && $this->landArea < 0) {
            throw new InvalidArgumentException('LandSpec land area cannot be negative.');
        }
        if ($this->buildableArea !== null && $this->buildableArea < 0) {
            throw new InvalidArgumentException('LandSpec buildable area cannot be negative.');
        }
        if ($this->landArea !== null && $this->buildableArea !== null && $this->buildableArea > $this->landArea) {
            throw new InvalidArgumentException('LandSpec buildable area cannot exceed land area.');
        }
    }
}
