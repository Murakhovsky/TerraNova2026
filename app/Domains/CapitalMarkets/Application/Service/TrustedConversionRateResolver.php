<?php
declare(strict_types=1);

namespace Domains\CapitalMarkets\Application\Service;

use DateTimeImmutable;
use Domains\CapitalMarkets\Application\Contract\MarketStateRepositoryInterface;
use Domains\CapitalMarkets\Domain\MarketData\ConversionRate;
use Domains\CapitalMarkets\Domain\Value\AssetCode;
use Domains\CapitalMarkets\Domain\Value\Decimal;
use Domains\CapitalMarkets\Domain\Value\DecimalMath;

final readonly class TrustedConversionRateResolver
{
    public function __construct(private MarketStateRepositoryInterface $marketStates){}

    public function resolve(
        string $organizationId,
        AssetCode $source,
        AssetCode $target,
        DateTimeImmutable $at,
        int $maxAgeMs=5000,
    ):?ConversionRate{
        if($source->equals($target))return null;
        $best=null;
        foreach($this->marketStates->list($organizationId,1000) as $state){
            if($state->bestQuote===null||!$state->quality->status->isUsableForDecision())continue;
            $age=$this->ageMs($state->sourceTimestamp,$at);
            if($age>$maxAgeMs)continue;
            $base=$state->bestQuote->bidPrice->baseAsset;
            $quote=$state->bestQuote->bidPrice->quoteAsset;
            $rate=null;
            if($base->equals($source)&&$quote->equals($target)){
                $rate=$state->bestQuote->midPrice();
            }elseif($base->equals($target)&&$quote->equals($source)){
                $rate=DecimalMath::divide(Decimal::fromString('1'),$state->bestQuote->midPrice(),18);
            }
            if($rate===null)continue;
            $candidate=new ConversionRate($source,$target,$rate,$state->sourceTimestamp,$state->sourceId,$state->quality->status);
            if($best===null||$state->sourceTimestamp>$best->timestamp)$best=$candidate;
        }
        return $best;
    }

    private function ageMs(DateTimeImmutable $source,DateTimeImmutable $at):int
    {
        $a=((int)$at->format('U')*1000000)+(int)$at->format('u');
        $b=((int)$source->format('U')*1000000)+(int)$source->format('u');
        return max(0,intdiv($a-$b,1000));
    }
}
