<?php
declare(strict_types=1);

namespace Domains\CapitalMarkets\Domain\Value;

use InvalidArgumentException;
use Kernel\Shared\Domain\ValueObject;

final readonly class Price extends ValueObject
{
    public function __construct(
        public Decimal $amount,
        public QuoteUnit $quoteUnit,
    ) {
        if (!$this->amount->isPositive()) {
            throw new InvalidArgumentException('Price must be greater than zero.');
        }
    }
}
