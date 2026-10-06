<?php
declare(strict_types=1);

namespace Domains\CapitalMarkets\Domain\Service;

use DateTimeImmutable;
use DomainException;
use Domains\CapitalMarkets\Domain\MarketData\CanonicalMarketEvent;
use Domains\CapitalMarkets\Domain\MarketData\MarketDataQualityAssessment;
use Domains\CapitalMarkets\Domain\MarketData\MarketQualityFlag;
use Domains\CapitalMarkets\Domain\MarketData\MarketQuote;
use Domains\CapitalMarkets\Domain\MarketData\MarketSession;
use Domains\CapitalMarkets\Domain\MarketData\ReferenceMarketState;
use Domains\CapitalMarkets\Domain\MarketData\ReferenceType;

final readonly class ReferenceMarketStateEngine
{
    public function apply(
        CanonicalMarketEvent $event,
        MarketDataQualityAssessment $quality,
        ?ReferenceMarketState $previous,
        MarketSession $session,
        ReferenceType $referenceType,
        DateTimeImmutable $now,
    ):ReferenceMarketState{
        if(!$event->observation instanceof MarketQuote){
            throw new DomainException('Reference MarketState currently requires a canonical quote/NBBO observation.');
        }
        if($previous!==null&&(
            in_array(MarketQualityFlag::Duplicate,$quality->flags,true)
            ||in_array(MarketQualityFlag::OutOfOrder,$quality->flags,true)
        )){
            return $previous;
        }

        $regular=$previous?->lastRegularMarketQuote;
        $extended=$previous?->lastExtendedQuote;
        if($session===MarketSession::Regular)$regular=$event->observation;
        if(in_array($session,[MarketSession::PreMarket,MarketSession::AfterHours,MarketSession::Overnight],true)){
            $extended=$event->observation;
        }

        return new ReferenceMarketState(
            $event->instrumentId,
            $event->sourceId,
            $event->observation,
            $session,
            $regular,
            $extended,
            $referenceType,
            max(0,$event->timestamps->ageMilliseconds($now)),
            $quality,
            $event->timestamps->processedTimestamp,
            ($previous?->stateVersion??0)+1,
            $event->mode,
            $event->timestamps->sourceTimestamp,
            $event->sequence??$previous?->lastSequence,
            $event->fingerprint(),
        );
    }
}
