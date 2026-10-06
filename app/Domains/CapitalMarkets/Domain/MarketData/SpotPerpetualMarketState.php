<?php
declare(strict_types=1);

namespace Domains\CapitalMarkets\Domain\MarketData;

use DateTimeImmutable;
use Domains\CapitalMarkets\Domain\Value\Decimal;
use InvalidArgumentException;
use Kernel\Shared\Domain\ValueObject;

final readonly class SpotPerpetualMarketState extends ValueObject
{
    public function __construct(
        public MarketState $spotState,
        public MarketState $perpetualState,
        public BasisObservation $basis,
        public FundingRateObservation $funding,
        public ?Decimal $markPrice,
        public ?Decimal $indexPrice,
        public ?Decimal $openInterest,
        public int $liquidityScore,
        public bool $comparable,
        public int $quality,
        public DateTimeImmutable $updatedAt,
    ){
        if($liquidityScore<0||$liquidityScore>100||$quality<0||$quality>100){
            throw new InvalidArgumentException('Market-state scores must be between 0 and 100.');
        }
    }

    public function trusted():bool
    {
        return $this->spotState->quality->status->isUsableForDecision()
            && $this->perpetualState->quality->status->isUsableForDecision()
            && $this->funding->valid()
            && $this->comparable
            && $this->quality>0;
    }
}
