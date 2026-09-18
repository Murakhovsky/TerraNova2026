<?php
declare(strict_types=1);

namespace Kernel\Observability;

use InvalidArgumentException;
use Stringable;

final readonly class CorrelationId implements Stringable
{
    private function __construct(private string $value)
    {
    }

    public static function generate(): self
    {
        return new self(bin2hex(random_bytes(16)));
    }

    public static function fromString(string $value): self
    {
        $value = trim($value);
        if ($value === '' || strlen($value) > 128 || preg_match('/^[A-Za-z0-9._:-]+$/', $value) !== 1) {
            throw new InvalidArgumentException('Correlation id is invalid.');
        }

        return new self($value);
    }

    public function value(): string
    {
        return $this->value;
    }

    public function __toString(): string
    {
        return $this->value;
    }
}
