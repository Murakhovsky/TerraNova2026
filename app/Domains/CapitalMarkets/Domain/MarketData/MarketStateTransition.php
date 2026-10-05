<?php
declare(strict_types=1);

namespace Domains\CapitalMarkets\Domain\MarketData;

final readonly class MarketStateTransition
{
    /** @param list<MarketQualityFlag> $flags */
    public function __construct(
        public MarketState $state,
        public bool $eventApplied,
        public bool $stateChanged,
        public array $flags=[],
    ){}
}
