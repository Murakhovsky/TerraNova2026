<?php
declare(strict_types=1);

namespace Domains\Property\Model;

use InvalidArgumentException;

final readonly class PropertyType
{
    public function __construct(
        public string $code,
        public ?int $referenceId = null,
    ) {
        $code = trim($this->code);
        if ($code === '' || !preg_match('/^[a-z][a-z0-9_-]{1,49}$/', $code)) {
            throw new InvalidArgumentException('PropertyType code must be a stable lowercase domain code.');
        }
        if ($this->referenceId !== null && $this->referenceId <= 0) {
            throw new InvalidArgumentException('PropertyType reference id must be positive when present.');
        }
    }

    public static function fromReference(int $referenceId, string $code): self
    {
        return new self(trim($code), $referenceId);
    }

    public function equals(self $other): bool
    {
        return $this->code === $other->code;
    }
}
