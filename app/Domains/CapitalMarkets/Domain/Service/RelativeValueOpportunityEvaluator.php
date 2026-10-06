<?php
declare(strict_types=1);

namespace Domains\CapitalMarkets\Domain\Service;

use Domains\CapitalMarkets\Domain\Instrument\SpotProfile;
use Domains\CapitalMarkets\Domain\MarketData\FundingRateObservation;
use Domains\CapitalMarkets\Domain\MarketData\FundingRateStatus;
use Domains\CapitalMarkets\Domain\MarketData\SpotPerpetualMarketState;
use Domains\CapitalMarkets\Domain\Opportunity\ExpectedEconomics;
use Domains\CapitalMarkets\Domain\Opportunity\OpportunityType;
use Domains\CapitalMarkets\Domain\Opportunity\RelativeValueEvaluation;
use Domains\CapitalMarkets\Domain\Research\ResearchResultStatus;
use Domains\CapitalMarkets\Domain\Value\Decimal;

final class RelativeValueOpportunityEvaluator
{
    public function basis(
        SpotPerpetualMarketState $state,
        SpotProfile $spot,
        ExpectedEconomics $economics,
        Decimal $minimumExecutableBasis,
        bool $reverse=false,
    ):RelativeValueEvaluation{
        if(!$state->trusted()){
            return $this->result(OpportunityType::SpotPerpBasis,ResearchResultStatus::InsufficientData,$economics,['MARKET_STATE_NOT_TRUSTED']);
        }
        if($reverse&&(!$spot->shortCapability||!$spot->borrowCapability)){
            return $this->result(OpportunityType::SpotPerpBasis,ResearchResultStatus::NotExecutable,$economics,['SPOT_SHORT_OR_BORROW_UNAVAILABLE']);
        }
        $basis=$reverse?$state->basis->shortSpotLongPerpExecutableBasis:$state->basis->longSpotShortPerpExecutableBasis;
        if($basis->compareTo($minimumExecutableBasis)<=0){
            return $this->result(OpportunityType::SpotPerpBasis,ResearchResultStatus::Rejected,$economics,['EXECUTABLE_BASIS_BELOW_THRESHOLD'],['executable_basis'=>$basis->value()]);
        }
        if(!$economics->expectedNetPnl->isPositive()){
            return $this->result(OpportunityType::SpotPerpBasis,ResearchResultStatus::Rejected,$economics,['EXPECTED_NET_CASHFLOW_NON_POSITIVE']);
        }
        return $this->result(OpportunityType::SpotPerpBasis,ResearchResultStatus::Validated,$economics,[],[
            'executable_basis'=>$basis->value(),
            'mid_basis_bps'=>$state->basis->midBasisBps->value(),
        ]);
    }

    public function fundingCapture(
        SpotPerpetualMarketState $state,
        ExpectedEconomics $economics,
    ):RelativeValueEvaluation{
        if(!$state->trusted()){
            return $this->result(OpportunityType::FundingCapture,ResearchResultStatus::InsufficientData,$economics,['MARKET_STATE_NOT_TRUSTED']);
        }
        if(!$economics->expectedNetPnl->isPositive()){
            return $this->result(OpportunityType::FundingCapture,ResearchResultStatus::Rejected,$economics,['EXPECTED_FUNDING_DOES_NOT_COVER_COSTS']);
        }
        $status=$state->funding->status===FundingRateStatus::Estimated
            ? ResearchResultStatus::PartiallyValidated
            : ResearchResultStatus::Validated;
        return $this->result(OpportunityType::FundingCapture,$status,$economics,[],[
            'funding_rate'=>$state->funding->rate->value(),
            'funding_status'=>$state->funding->status->value,
            'next_settlement_at'=>$state->funding->nextSettlementAt?->format(DATE_ATOM),
        ]);
    }

    public function crossVenueFunding(
        FundingRateObservation $longVenueFunding,
        FundingRateObservation $shortVenueFunding,
        ExpectedEconomics $economics,
    ):RelativeValueEvaluation{
        if(!$longVenueFunding->valid()||!$shortVenueFunding->valid()){
            return $this->result(OpportunityType::CrossVenueFunding,ResearchResultStatus::InsufficientData,$economics,['FUNDING_DATA_INVALID']);
        }
        if($longVenueFunding->venue->equals($shortVenueFunding->venue)){
            return $this->result(OpportunityType::CrossVenueFunding,ResearchResultStatus::NotExecutable,$economics,['CROSS_VENUE_REQUIRES_DISTINCT_VENUES']);
        }
        if(!$economics->expectedNetPnl->isPositive()){
            return $this->result(OpportunityType::CrossVenueFunding,ResearchResultStatus::Rejected,$economics,['EXPECTED_NET_CASHFLOW_NON_POSITIVE']);
        }
        $estimated=$longVenueFunding->status===FundingRateStatus::Estimated||$shortVenueFunding->status===FundingRateStatus::Estimated;
        return $this->result(
            OpportunityType::CrossVenueFunding,
            $estimated?ResearchResultStatus::PartiallyValidated:ResearchResultStatus::Validated,
            $economics,[],
            [
                'long_venue_rate'=>$longVenueFunding->rate->value(),
                'long_venue_interval_seconds'=>$longVenueFunding->fundingIntervalSeconds,
                'short_venue_rate'=>$shortVenueFunding->rate->value(),
                'short_venue_interval_seconds'=>$shortVenueFunding->fundingIntervalSeconds,
            ],
        );
    }

    /** @param list<string> $reasons @param array<string,mixed> $evidence */
    private function result(OpportunityType $type,ResearchResultStatus $status,ExpectedEconomics $economics,array $reasons,array $evidence=[]):RelativeValueEvaluation
    {
        return new RelativeValueEvaluation($type,$status,$economics->expectedNetPnl,$reasons,$evidence);
    }
}
