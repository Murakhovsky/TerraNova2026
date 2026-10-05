<?php
declare(strict_types=1);

namespace Domains\CapitalMarkets\Domain\Value;

use InvalidArgumentException;
use Kernel\Shared\Domain\ValueObject;

final readonly class Decimal extends ValueObject
{
    private string $value;

    private function __construct(string $value)
    {
        $value = trim($value);
        if (preg_match('/^[+-]?[0-9]+(?:\.[0-9]+)?$/', $value) !== 1) {
            throw new InvalidArgumentException('Decimal value must be an explicit base-10 string.');
        }

        $negative = str_starts_with($value, '-');
        if ($negative || str_starts_with($value, '+')) {
            $value = substr($value, 1);
        }

        [$integer, $fraction] = array_pad(explode('.', $value, 2), 2, '');
        $integer = ltrim($integer, '0');
        $integer = $integer === '' ? '0' : $integer;
        $fraction = rtrim($fraction, '0');

        $canonical = $fraction === '' ? $integer : $integer . '.' . $fraction;
        if ($canonical !== '0' && $negative) {
            $canonical = '-' . $canonical;
        }

        $this->value = $canonical;
    }

    public static function fromString(string $value): self
    {
        return new self($value);
    }

    public function value(): string
    {
        return $this->value;
    }

    public function isZero(): bool
    {
        return $this->value === '0';
    }

    public function isNegative(): bool
    {
        return str_starts_with($this->value, '-');
    }

    public function isPositive(): bool
    {
        return !$this->isZero() && !$this->isNegative();
    }

    public function __toString(): string
    {
        return $this->value;
    }
}
