<?php
declare(strict_types=1);

namespace Domains\CapitalMarkets\Domain\Value;

use InvalidArgumentException;
use Kernel\Shared\Domain\ValueObject;
use Stringable;

final readonly class QuoteUnit extends ValueObject implements Stringable
{
    private string $value;

    public function __construct(string $value)
    {
        $value = strtoupper(trim($value));
        if (preg_match('/^[A-Z0-9][A-Z0-9._:-]{1,19}$/', $value) !== 1) {
            throw new InvalidArgumentException('Quote unit must be a stable 2-20 character market unit such as USD, BTC or USDT.');
        }
        $this->value = $value;
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
