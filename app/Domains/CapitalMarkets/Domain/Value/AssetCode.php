<?php
declare(strict_types=1);

namespace Domains\CapitalMarkets\Domain\Value;

use InvalidArgumentException;
use Kernel\Shared\Domain\ValueObject;
use Stringable;

final readonly class AssetCode extends ValueObject implements Stringable
{
    private string $value;

    public function __construct(string $value)
    {
        $value = strtoupper(trim($value));
        if (preg_match('/^[A-Z0-9][A-Z0-9._:-]{1,19}$/', $value) !== 1) {
            throw new InvalidArgumentException('Asset code must contain 2-20 stable market characters.');
        }
        $this->value = $value;
    }

    public function value(): string { return $this->value; }
    public function __toString(): string { return $this->value; }
}
