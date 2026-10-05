<?php
declare(strict_types=1);

namespace Domains\CapitalMarkets\Domain\Instrument;

use InvalidArgumentException;
use Kernel\Shared\Domain\ValueObject;

final readonly class MarketPair extends ValueObject
{
    public function __construct(
        public InstrumentId $left,
        public InstrumentId $right,
    ) {
        if ($this->left->equals($this->right)) {
            throw new InvalidArgumentException('Market pair requires two different instruments.');
        }
    }
}
