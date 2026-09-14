<?php
declare(strict_types=1);

namespace Domains\Property\Model;

use InvalidArgumentException;

final readonly class LocationNode
{
    public function __construct(
        public string $nodeId,
        public LocationNodeType $type,
        public string $name,
        public string $canonicalKey,
        public ?string $parentNodeId = null,
        public ?string $countryCode = null,
    ) {
        if (!preg_match('/^[A-Za-z0-9][A-Za-z0-9._:-]{1,79}$/', $this->nodeId)) {
            throw new InvalidArgumentException('LocationNode requires a stable node identifier.');
        }
        if (trim($this->name) === '' || trim($this->canonicalKey) === '') {
            throw new InvalidArgumentException('LocationNode name and canonical key are required.');
        }
        if ($this->parentNodeId !== null && $this->parentNodeId === $this->nodeId) {
            throw new InvalidArgumentException('LocationNode cannot be its own parent.');
        }
        if ($this->countryCode !== null && !preg_match('/^[A-Z]{2}$/', $this->countryCode)) {
            throw new InvalidArgumentException('LocationNode country code must be ISO-3166 alpha-2.');
        }
        if ($this->type->value === LocationNodeType::COUNTRY && $this->parentNodeId !== null) {
            throw new InvalidArgumentException('Country LocationNode cannot have a parent.');
        }
    }
}
