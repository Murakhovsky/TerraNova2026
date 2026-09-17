<?php
declare(strict_types=1);

namespace Kernel\Shared\Domain;

use InvalidArgumentException;
use Stringable;

abstract readonly class Identifier extends ValueObject implements Stringable
{
    final protected function __construct(private string $value)
    {
        $value = trim($value);
        if ($value === '' || strlen($value) > 190) {
            throw new InvalidArgumentException('Identifier must contain between 1 and 190 characters.');
        }

        $this->value = $value;
    }

    final public static function fromString(string $value): static
    {
        return new static($value);
    }

    final public function value(): string
    {
        return $this->value;
    }

    final public function __toString(): string
    {
        return $this->value;
    }
}
