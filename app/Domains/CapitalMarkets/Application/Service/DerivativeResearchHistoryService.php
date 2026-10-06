<?php
declare(strict_types=1);

namespace Domains\CapitalMarkets\Application\Service;

use DateTimeImmutable;
use Domains\CapitalMarkets\Application\Contract\RelativeValueResearchRepositoryInterface;
use Domains\CapitalMarkets\Domain\Contract\DerivativeMarketDataAdapterInterface;
use Domains\CapitalMarkets\Domain\MarketData\FundingRateObservation;
use Domains\CapitalMarkets\Domain\MarketData\MarketDataInstrumentTarget;
use Domains\CapitalMarkets\Domain\MarketData\MarketSourceDescriptor;
use Domains\CapitalMarkets\Domain\Service\FundingStatisticsEngine;
use DomainException;

final readonly class DerivativeResearchHistoryService
{
    public function __construct(
        private MarketDataAdapterRegistry $adapters,
        private RelativeValueResearchRepositoryInterface $research,
        private FundingStatisticsEngine $statistics,
    ){}

    /** @return array<string,mixed> */
    public function importFundingHistory(
        string $organizationId,
        MarketSourceDescriptor $source,
        MarketDataInstrumentTarget $target,
        DateTimeImmutable $from,
        DateTimeImmutable $to,
        int $limit=200,
    ):array{
        $adapter=$this->adapters->get($source->adapterType);
        if(!$adapter instanceof DerivativeMarketDataAdapterInterface){
            throw new DomainException('MARKET_DATA_ADAPTER_HAS_NO_DERIVATIVE_HISTORY_CAPABILITY');
        }
        $observations=$adapter->getFundingHistory($organizationId,$source,$target,$from,$to,$limit);
        foreach($observations as $observation){
            $id=$this->id($organizationId,$observation);
            $this->research->saveFundingObservation($organizationId,$id,$observation);
        }
        return [
            'source_id'=>$source->id->value(),
            'venue_id'=>$source->venueId?->value(),
            'instrument_id'=>$target->instrument->id->value(),
            'from'=>$from->format(DATE_ATOM),'to'=>$to->format(DATE_ATOM),
            'imported'=>count($observations),
            'statistics'=>$this->statistics->summarize($observations),
        ];
    }

    private function id(string $organizationId,FundingRateObservation $observation):string
    {
        return 'cm_funding_'.substr(hash('sha256',implode('|',[
            $organizationId,$observation->venue->value(),$observation->perpetualInstrument->value(),
            $observation->observationTimestamp->format('U.u'),$observation->rate->value(),$observation->status->value,
        ])),0,40);
    }
}
