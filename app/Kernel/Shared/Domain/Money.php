<?php
declare(strict_types=1);

namespace Kernel\Shared\Domain;

use InvalidArgumentException;

final readonly class Money extends ValueObject
{
    private int $minorUnits;
    private string $currency;

    public function __construct(int $minorUnits, string $currency)
    {
        $currency = strtoupper(trim($currency));
        if (preg_match('/^[A-Z]{3}$/', $currency) !== 1) {
            throw new InvalidArgumentException('Currency must be an ISO-like three-letter code.');
        }
        $this->minorUnits = $minorUnits;
        $this->currency = $currency;
    }

    public function minorUnits(): int { return $this->minorUnits; }
    public function currency(): string { return $this->currency; }

    public function add(self $other): self
    {
        $this->assertSameCurrency($other);
        return new self($this->minorUnits + $other->minorUnits, $this->currency);
    }

    public function subtract(self $other): self
    {
        $this->assertSameCurrency($other);
        return new self($this->minorUnits - $other->minorUnits, $this->currency);
    }

    public function compareTo(self $other): int
    {
        $this->assertSameCurrency($other);
        return $this->minorUnits <=> $other->minorUnits;
    }

    public function isZero(): bool { return $this->minorUnits === 0; }

    /** @return array{minor_units:int,currency:string} */
    public function toArray(): array
    {
        return ['minor_units' => $this->minorUnits, 'currency' => $this->currency];
    }

    private function assertSameCurrency(self $other): void
    {
        if ($this->currency !== $other->currency) {
            throw new InvariantViolation('Money operations require the same currency.');
        }
    }
}
