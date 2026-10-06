<?php
declare(strict_types=1);

namespace Domains\CapitalMarkets\Application\Contract;

use Domains\CapitalMarkets\Domain\MarketData\BasisObservation;
use Domains\CapitalMarkets\Domain\MarketData\FundingRateObservation;
use Domains\CapitalMarkets\Domain\MarketData\FundingSettlement;
use Domains\CapitalMarkets\Domain\Portfolio\HedgeGroup;

interface RelativeValueResearchRepositoryInterface
{
    public function saveFundingObservation(string $organizationId,string $observationId,FundingRateObservation $observation):void;
    /** @return list<array<string,mixed>> */
    public function listFundingObservations(string $organizationId,?string $venueId=null,?string $instrumentId=null,int $limit=5000):array;

    public function saveBasisObservation(string $organizationId,string $observationId,string $marketPairId,BasisObservation $observation):void;
    /** @return list<array<string,mixed>> */
    public function listBasisObservations(string $organizationId,string $marketPairId,int $limit=5000):array;

    public function saveFundingSettlement(string $organizationId,string $settlementId,FundingSettlement $settlement):void;
    /** @return list<array<string,mixed>> */
    public function listFundingSettlements(string $organizationId,?string $positionReference=null,int $limit=5000):array;

    public function saveHedgeGroup(
        string $organizationId,HedgeGroup $group,?string $opportunityId=null,?string $executionId=null
    ):void;

    /** @return array<string,mixed>|null */
    public function getHedgeGroup(string $organizationId,string $hedgeGroupId):?array;
}
