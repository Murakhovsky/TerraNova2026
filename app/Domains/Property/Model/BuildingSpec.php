<?php
declare(strict_types=1);

namespace Domains\Property\Model;

use InvalidArgumentException;

final readonly class BuildingSpec
{
    public function __construct(
        public ?float $grossArea = null,
        public ?int $floors = null,
        public ?int $builtYear = null,
    ) {
        if ($this->grossArea !== null && $this->grossArea < 0) {
            throw new InvalidArgumentException('BuildingSpec gross area cannot be negative.');
        }
        if ($this->floors !== null && $this->floors <= 0) {
            throw new InvalidArgumentException('BuildingSpec floors must be positive when present.');
        }
        if ($this->builtYear !== null && ($this->builtYear < 1000 || $this->builtYear > 2200)) {
            throw new InvalidArgumentException('BuildingSpec built year is outside the supported physical range.');
        }
    }
}
