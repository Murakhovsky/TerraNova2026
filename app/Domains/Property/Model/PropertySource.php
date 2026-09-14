<?php
declare(strict_types=1);

namespace Domains\Property\Model;

use InvalidArgumentException;

final readonly class PropertySource
{
    public function __construct(
        public string $organizationId,
        public string $sourceId,
        public PropertySourceType $type,
        public string $sourceSystem,
        public float $trustLevel = 0.5,
        public ?string $partyReference = null,
    ) {
        if (trim($this->organizationId) === '') {
            throw new InvalidArgumentException('PropertySource organization id is required.');
        }
        if (!preg_match('/^[A-Za-z0-9][A-Za-z0-9._:-]{2,79}$/', $this->sourceId)) {
            throw new InvalidArgumentException('PropertySource source id must be stable.');
        }
        if (!preg_match('/^[A-Za-z0-9][A-Za-z0-9._:-]{1,79}$/', $this->sourceSystem)) {
            throw new InvalidArgumentException('PropertySource source system must be a stable system code.');
        }
        if ($this->trustLevel < 0.0 || $this->trustLevel > 1.0) {
            throw new InvalidArgumentException('PropertySource trust level must be between 0 and 1.');
        }
        if ($this->partyReference !== null && trim($this->partyReference) === '') {
            throw new InvalidArgumentException('PropertySource party reference cannot be blank.');
        }
    }
}
