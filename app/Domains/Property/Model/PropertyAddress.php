<?php
declare(strict_types=1);

namespace Domains\Property\Model;

use InvalidArgumentException;

final readonly class PropertyAddress
{
    public function __construct(
        public string $localityNodeId,
        public ?string $streetNodeId = null,
        public ?string $houseNumber = null,
        public ?string $buildingPart = null,
        public ?string $unitLabel = null,
        public ?string $postalCode = null,
        public ?string $formattedAddress = null,
    ) {
        if (trim($this->localityNodeId) === '') {
            throw new InvalidArgumentException('PropertyAddress locality node is required.');
        }
        if ($this->streetNodeId === null && trim((string) $this->formattedAddress) === '') {
            throw new InvalidArgumentException('PropertyAddress requires a street node or formatted address.');
        }
    }
}
