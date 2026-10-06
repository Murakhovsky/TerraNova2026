<?php
declare(strict_types=1);

namespace Domains\CapitalMarkets\Application\Service;

use DateTimeImmutable;
use DomainException;
use Domains\CapitalMarkets\Application\Contract\CapitalMarketsTradingRepositoryInterface;
use Domains\CapitalMarkets\Application\Contract\MarketStateRepositoryInterface;
use Domains\CapitalMarkets\Application\Contract\RelativeValueResearchRepositoryInterface;
use Domains\CapitalMarkets\Domain\Contract\RelationshipRepository;
use Domains\CapitalMarkets\Domain\Instrument\EconomicRelationship;
use Domains\CapitalMarkets\Domain\Instrument\InstrumentId;
use Domains\CapitalMarkets\Domain\Instrument\SpotProfile;
use Domains\CapitalMarkets\Domain\MarketData\FundingRateObservation;
use Domains\CapitalMarkets\Domain\MarketData\FundingRateStatus;
use Domains\CapitalMarkets\Domain\MarketData\FundingRateType;
use Domains\CapitalMarkets\Domain\MarketData\MarketEventType;
use Domains\CapitalMarkets\Domain\MarketData\MarketState;
use Domains\CapitalMarkets\Domain\Opportunity\ExpectedEconomics;
use Domains\CapitalMarkets\Domain\Opportunity\HypothesisCode;
use Domains\CapitalMarkets\Domain\Opportunity\Opportunity;
use Domains\CapitalMarkets\Domain\Opportunity\OpportunityStatus;
use Domains\CapitalMarkets\Domain\Opportunity\OpportunityType;
use Domains\CapitalMarkets\Domain\Opportunity\RelativeValueCandidate;
use Domains\CapitalMarkets\Domain\Opportunity\RelativeValueEvaluation;
use Domains\CapitalMarkets\Domain\Portfolio\HedgeGroup;
use Domains\CapitalMarkets\Domain\Portfolio\HedgeLeg;
use Domains\CapitalMarkets\Domain\Portfolio\HedgeState;
use Domains\CapitalMarkets\Domain\Portfolio\PositionSide;
use Domains\CapitalMarkets\Domain\Research\ResearchResultStatus;
use Domains\CapitalMarkets\Domain\Risk\DerivativesRiskPolicy;
use Domains\CapitalMarkets\Domain\Risk\LiquidationRiskState;
use Domains\CapitalMarkets\Domain\Risk\LiquidationRiskStatus;
use Domains\CapitalMarkets\Domain\Risk\RiskAssessment;
use Domains\CapitalMarkets\Domain\Risk\RiskDecision;
use Domains\CapitalMarkets\Domain\Service\RelativeValueEconomicsCalculator;
use Domains\CapitalMarkets\Domain\Service\RelativeValueOpportunityEvaluator;
use Domains\CapitalMarkets\Domain\Service\RelativeValueRiskEvaluator;
use Domains\CapitalMarkets\Domain\Service\SpotPerpetualMarketStateFactory;
use Domains\CapitalMarkets\Domain\Value\AssetCode;
use Domains\CapitalMarkets\Domain\Value\Decimal;
use Domains\CapitalMarkets\Domain\Value\DecimalMath;
use Domains\CapitalMarkets\Domain\Venue\VenueId;
use InvalidArgumentException;

final readonly class CryptoSpotPerpetualVerticalSliceService
{
    public function __construct(
        private MarketStateRepositoryInterface $marketStates,
        private CapitalMarketsTradingRepositoryInterface $trading,
        private RelativeValueResearchRepositoryInterface $research,
        private RelationshipRepository $relationships,
        private SpotPerpetualMarketStateFactory $stateFactory,
        private RelativeValueEconomicsCalculator $economics,
        private RelativeValueOpportunityEvaluator $opportunities,
        private RelativeValueRiskEvaluator $risk,
    ){}

    /** @param array<string,mixed> $options @return array<string,mixed> */
    public function scanSpotPerp(
        string $organizationId,
        string $marketPairId,
        string $spotVenueId,
        string $spotInstrumentId,
        string $perpVenueId,
        string $perpInstrumentId,
        array $options,
    ):array{
        $now=new DateTimeImmutable();
        $spot=$this->marketStates->get($organizationId,VenueId::fromString($spotVenueId),InstrumentId::fromString($spotInstrumentId));
        $perp=$this->marketStates->get($organizationId,VenueId::fromString($perpVenueId),InstrumentId::fromString($perpInstrumentId));
        if($spot===null||$perp===null){
            foreach([HypothesisCode::SpotPerpetualBasis,HypothesisCode::FundingRateCapture] as $hypothesis){
                $this->saveScan($organizationId,$hypothesis,$marketPairId,$now,false,false,'MARKET_STATE_UNAVAILABLE');
            }
            return ['market_pair_id'=>$marketPairId,'h4'=>null,'h5'=>null,'issues'=>['MARKET_STATE_UNAVAILABLE']];
        }

        $related=$this->economicallyRelated($organizationId,$spot->instrumentId,$perp->instrumentId,$now);
        try{$state=$this->stateFactory->build($spot,$perp,$related);}
        catch(\Throwable $error){
            foreach([HypothesisCode::SpotPerpetualBasis,HypothesisCode::FundingRateCapture] as $hypothesis){
                $this->saveScan($organizationId,$hypothesis,$marketPairId,$now,false,false,$error->getMessage());
            }
            return ['market_pair_id'=>$marketPairId,'h4'=>null,'h5'=>null,'issues'=>[$error->getMessage()]];
        }

        $basisId='cm_basis_'.substr(hash('sha256',$organizationId.'|'.$marketPairId.'|'.$state->updatedAt->format('U.u')),0,40);
        $fundingId='cm_funding_'.substr(hash('sha256',$organizationId.'|'.$perp->key().'|'.$state->funding->observationTimestamp->format('U.u')),0,40);
        $this->research->saveBasisObservation($organizationId,$basisId,$marketPairId,$state->basis);
        $this->research->saveFundingObservation($organizationId,$fundingId,$state->funding);

        $quantity=$this->executableQuantity($spot,$perp,$this->requiredDecimal($options,'quantity'));
        if(!$quantity->isPositive())throw new DomainException('NO_EXECUTABLE_SPOT_PERP_QUANTITY');

        $common=$this->commonEconomicsInputs($options);
        $h4Economics=$this->economics->spotPerp(
            $state,$quantity,$common['horizon'],$common['spot_fee'],$common['perp_fee'],$common['slippage_bps'],
            $common['leverage'],$this->decimal($options,'target_basis_absolute',Decimal::fromString('0')),
            $common['risk_allowance'],$common['network_costs'],$common['emergency_buffer'],true,
        );
        $h5Economics=$this->economics->spotPerp(
            $state,$quantity,$common['horizon'],$common['spot_fee'],$common['perp_fee'],$common['slippage_bps'],
            $common['leverage'],Decimal::fromString('0'),
            $common['risk_allowance'],$common['network_costs'],$common['emergency_buffer'],false,
        );

        $spotProfile=new SpotProfile(
            $spot->bestQuote?->askPrice->baseAsset??throw new DomainException('SPOT_BASE_ASSET_UNAVAILABLE'),
            $spot->bestQuote?->askPrice->quoteAsset??throw new DomainException('SPOT_QUOTE_ASSET_UNAVAILABLE'),
            Decimal::fromString('0'),Decimal::fromString('0'),
            $spot->bestQuote->askPrice->precision,$spot->bestQuote->askQuantity->precision,
            $this->bool($options,'spot_margin_capability',false),
            $this->bool($options,'spot_short_capability',false),
            $this->bool($options,'spot_borrow_capability',false),
        );

        $h4Eval=$this->opportunities->basis(
            $state,$spotProfile,$h4Economics,$this->decimal($options,'minimum_executable_basis',Decimal::fromString('0'))
        );
        $h5Eval=$this->opportunities->fundingCapture($state,$h5Economics);

        $hedge=$this->spotPerpHedge($spot,$perp,$quantity,(string)($options['strategy_version']??'FundingCaptureStrategy-v1'));
        $riskPolicy=$this->riskPolicy($options);
        $liquidation=$this->liquidation($state->markPrice??$state->basis->perpMid,$options,'');
        $risk=$this->risk->assess($state,$hedge,$riskPolicy,$liquidation,$common['leverage']);

        $this->saveScan($organizationId,HypothesisCode::SpotPerpetualBasis,$marketPairId,$now,$state->trusted(),true,null);
        $this->saveScan($organizationId,HypothesisCode::FundingRateCapture,$marketPairId,$now,$state->trusted(),true,null);

        $h4=$this->persistEvaluation(
            $organizationId,$marketPairId,HypothesisCode::SpotPerpetualBasis,OpportunityType::SpotPerpBasis,
            $h4Eval,$h4Economics,$hedge,$risk,$quantity,$now,
            $state->basis->midBasisBps,
            [
                'basis_observation_id'=>$basisId,'funding_observation_id'=>$fundingId,
                'spot_bid'=>$state->basis->spotBid->value(),'spot_ask'=>$state->basis->spotAsk->value(),
                'perp_bid'=>$state->basis->perpBid->value(),'perp_ask'=>$state->basis->perpAsk->value(),
                'mark_price'=>$state->markPrice?->value(),'index_price'=>$state->indexPrice?->value(),
                'funding_rate'=>$state->funding->rate->value(),'next_funding'=>$state->funding->nextSettlementAt?->format(DATE_ATOM),
                'data_quality_score'=>$state->quality,'candidate_ttl_ms'=>$this->int($options,'candidate_ttl_ms',1500),
            ],
            [
                $this->leg($spot,PositionSide::Long,$quantity,$state->basis->spotAsk,'SPOT'),
                $this->leg($perp,PositionSide::Short,$quantity,$state->basis->perpBid,'PERPETUAL'),
            ],
            (string)($options['basis_strategy_version']??'SpotPerpBasisStrategy-v1'),
        );

        $h5=$this->persistEvaluation(
            $organizationId,$marketPairId,HypothesisCode::FundingRateCapture,OpportunityType::FundingCapture,
            $h5Eval,$h5Economics,$hedge,$risk,$quantity,$now,
            DecimalMath::multiplyInteger($state->funding->rate,10000),
            [
                'basis_observation_id'=>$basisId,'funding_observation_id'=>$fundingId,
                'current_funding'=>$state->funding->rate->value(),'funding_status'=>$state->funding->status->value,
                'funding_interval_seconds'=>$state->funding->fundingIntervalSeconds,
                'next_funding'=>$state->funding->nextSettlementAt?->format(DATE_ATOM),
                'basis_bps'=>$state->basis->midBasisBps->value(),
                'data_quality_score'=>$state->quality,'candidate_ttl_ms'=>$this->int($options,'candidate_ttl_ms',1500),
            ],
            [
                $this->leg($spot,PositionSide::Long,$quantity,$state->basis->spotAsk,'SPOT'),
                $this->leg($perp,PositionSide::Short,$quantity,$state->basis->perpBid,'PERPETUAL'),
            ],
            (string)($options['funding_strategy_version']??'FundingCaptureStrategy-v1'),
        );

        $this->research->saveHedgeGroup($organizationId,$hedge,$h5['opportunity_id']??$h4['opportunity_id']??null,null);
        return ['market_pair_id'=>$marketPairId,'market_state_trusted'=>$state->trusted(),'h4'=>$h4,'h5'=>$h5,'issues'=>[]];
    }

    /** @param array<string,mixed> $options @return array<string,mixed> */
    public function scanCrossVenueFunding(
        string $organizationId,
        string $marketPairId,
        string $venueA,
        string $instrumentA,
        string $venueB,
        string $instrumentB,
        array $options,
    ):array{
        $now=new DateTimeImmutable();
        $a=$this->marketStates->get($organizationId,VenueId::fromString($venueA),InstrumentId::fromString($instrumentA));
        $b=$this->marketStates->get($organizationId,VenueId::fromString($venueB),InstrumentId::fromString($instrumentB));
        if($a===null||$b===null){
            $this->saveScan($organizationId,HypothesisCode::CrossVenueFunding,$marketPairId,$now,false,false,'MARKET_STATE_UNAVAILABLE');
            return ['market_pair_id'=>$marketPairId,'h6'=>null,'issues'=>['MARKET_STATE_UNAVAILABLE']];
        }
        if($a->venueId->equals($b->venueId))throw new InvalidArgumentException('H6 requires distinct venues.');
        if(!$this->economicallyRelated($organizationId,$a->instrumentId,$b->instrumentId,$now)){
            $this->saveScan($organizationId,HypothesisCode::CrossVenueFunding,$marketPairId,$now,false,false,'ECONOMIC_RELATIONSHIP_REQUIRED');
            return ['market_pair_id'=>$marketPairId,'h6'=>null,'issues'=>['ECONOMIC_RELATIONSHIP_REQUIRED']];
        }
        $fundingA=$this->fundingFromState($a);
        $fundingB=$this->fundingFromState($b);
        $fundingAId='cm_funding_'.substr(hash('sha256',$organizationId.'|'.$a->key().'|'.$fundingA->observationTimestamp->format('U.u')),0,40);
        $fundingBId='cm_funding_'.substr(hash('sha256',$organizationId.'|'.$b->key().'|'.$fundingB->observationTimestamp->format('U.u')),0,40);
        $this->research->saveFundingObservation($organizationId,$fundingAId,$fundingA);
        $this->research->saveFundingObservation($organizationId,$fundingBId,$fundingB);

        if($a->bestQuote===null||$b->bestQuote===null)throw new DomainException('H6_BBO_REQUIRED');
        $requested=$this->requiredDecimal($options,'quantity');
        $available=$this->minimum([
            $a->bestQuote->askQuantity->value,$a->bestQuote->bidQuantity->value,
            $b->bestQuote->askQuantity->value,$b->bestQuote->bidQuantity->value,
        ]);
        $quantity=$requested->compareTo($available)<=0?$requested:$available;

        $horizon=$this->requiredInt($options,'holding_horizon_seconds');
        $feeA=$this->requiredDecimal($options,'venue_a_fee_rate');
        $feeB=$this->requiredDecimal($options,'venue_b_fee_rate');
        $slippage=$this->requiredDecimal($options,'round_trip_slippage_bps');
        $levA=$this->requiredDecimal($options,'venue_a_leverage');
        $levB=$this->requiredDecimal($options,'venue_b_leverage');
        $riskAllowance=$this->requiredDecimal($options,'risk_allowance');
        $network=$this->decimal($options,'network_costs',Decimal::fromString('0'));
        $emergency=$this->requiredDecimal($options,'emergency_hedge_buffer');
        $markA=$a->markPrice?->value??$a->bestQuote->midPrice();
        $markB=$b->markPrice?->value??$b->bestQuote->midPrice();

        $ab=$this->economics->crossVenueFunding(
            $fundingA,$fundingB,$markA,$markB,$quantity,$horizon,$feeA,$feeB,$slippage,$levA,$levB,
            $riskAllowance,$network,$emergency,$now
        );
        $ba=$this->economics->crossVenueFunding(
            $fundingB,$fundingA,$markB,$markA,$quantity,$horizon,$feeB,$feeA,$slippage,$levB,$levA,
            $riskAllowance,$network,$emergency,$now
        );

        $aLong=$ab->expectedNetPnl->compareTo($ba->expectedNetPnl)>=0;
        $longState=$aLong?$a:$b;$shortState=$aLong?$b:$a;
        $longFunding=$aLong?$fundingA:$fundingB;$shortFunding=$aLong?$fundingB:$fundingA;
        $economics=$aLong?$ab:$ba;
        $evaluation=$this->opportunities->crossVenueFunding($longFunding,$shortFunding,$economics);

        $hedge=new HedgeGroup(
            'cm_hg_'.substr(hash('sha256',$marketPairId.'|H6|'.$now->format('U.u')),0,40),
            (string)($options['strategy_version']??'CrossVenueFundingStrategy-v1'),
            [
                new HedgeLeg($longState->instrumentId->value(),$longState->venueId->value(),PositionSide::Long,$quantity,Decimal::fromString('1'),Decimal::fromString('1')),
                new HedgeLeg($shortState->instrumentId->value(),$shortState->venueId->value(),PositionSide::Short,$quantity,Decimal::fromString('1'),Decimal::fromString('1')),
            ],
            Decimal::fromString('0'),$this->decimal($options,'delta_tolerance',Decimal::fromString('0.000001')),HedgeState::Planned,
        );
        $policy=$this->riskPolicy($options);
        $liqLong=$this->liquidation($longState->markPrice?->value??$longState->bestQuote->midPrice(),$options,'long_');
        $liqShort=$this->liquidation($shortState->markPrice?->value??$shortState->bestQuote->midPrice(),$options,'short_');
        $leverage=$levA->compareTo($levB)>=0?$levA:$levB;
        $marketTrusted=$a->quality->status->isUsableForDecision()&&$b->quality->status->isUsableForDecision()
            &&$fundingA->valid()&&$fundingB->valid();
        $risk=$this->risk->assessHedge($marketTrusted,$hedge,$policy,[$liqLong,$liqShort],$leverage);

        $this->saveScan($organizationId,HypothesisCode::CrossVenueFunding,$marketPairId,$now,$marketTrusted,true,null);
        $signal=DecimalMath::multiplyInteger(DecimalMath::subtract($shortFunding->rate,$longFunding->rate),10000);
        $h6=$this->persistEvaluation(
            $organizationId,$marketPairId,HypothesisCode::CrossVenueFunding,OpportunityType::CrossVenueFunding,
            $evaluation,$economics,$hedge,$risk,$quantity,$now,$signal,
            [
                'funding_observation_ids'=>[$fundingAId,$fundingBId],
                'venue_a_rate'=>$fundingA->rate->value(),'venue_a_interval_seconds'=>$fundingA->fundingIntervalSeconds,
                'venue_b_rate'=>$fundingB->rate->value(),'venue_b_interval_seconds'=>$fundingB->fundingIntervalSeconds,
                'direction'=>$aLong?'LONG_A_SHORT_B':'LONG_B_SHORT_A',
                'data_quality_score'=>min($a->quality->score,$b->quality->score),
                'candidate_ttl_ms'=>$this->int($options,'candidate_ttl_ms',1500),
            ],
            [
                $this->leg($longState,PositionSide::Long,$quantity,$longState->bestQuote->askPrice->value,'PERPETUAL'),
                $this->leg($shortState,PositionSide::Short,$quantity,$shortState->bestQuote->bidPrice->value,'PERPETUAL'),
            ],
            (string)($options['strategy_version']??'CrossVenueFundingStrategy-v1'),
        );
        $this->research->saveHedgeGroup($organizationId,$hedge,$h6['opportunity_id']??null,null);
        return ['market_pair_id'=>$marketPairId,'market_state_trusted'=>$marketTrusted,'h6'=>$h6,'issues'=>[]];
    }

    /** @param array<string,mixed> $evidence @param list<array<string,mixed>> $legs @return array<string,mixed> */
    private function persistEvaluation(
        string $organizationId,string $marketPairId,HypothesisCode $hypothesis,OpportunityType $type,
        RelativeValueEvaluation $evaluation,ExpectedEconomics $economics,HedgeGroup $hedge,array $risk,
        Decimal $quantity,DateTimeImmutable $now,Decimal $signalBps,array $evidence,array $legs,string $strategyVersion,
    ):array{
        $ttlMs=max(100,(int)($evidence['candidate_ttl_ms']??1500));
        $expires=$now->modify('+'.$ttlMs.' milliseconds');
        $candidateId='cm_candidate_'.substr(hash('sha256',implode('|',[
            $organizationId,$hypothesis->value,$marketPairId,$now->format('U.u'),$strategyVersion
        ])),0,40);
        $candidate=new RelativeValueCandidate(
            $candidateId,$hypothesis,$type,$marketPairId,$now,$expires,$legs,
            $economics->capitalRequired,$economics->capitalRequired,
            min((int)($evidence['data_quality_score']??100),$this->qualityFromRisk($risk)),$evidence,
        );
        $candidatePayload=[
            'id'=>$candidateId,'hypothesis'=>$hypothesis->value,'type'=>$type->value,'market_pair_id'=>$marketPairId,
            'detected_at'=>$now->format(DATE_ATOM),'expires_at'=>$expires->format(DATE_ATOM),
            'gross_edge_bps'=>$signalBps->value(),'signal_bps'=>$signalBps->value(),'quantity'=>$quantity->value(),
            'required_capital'=>$economics->capitalRequired->value(),'strategy_version'=>$strategyVersion,
            'legs'=>$legs,'evidence'=>$evidence,
        ];
        $candidateStatus=in_array($evaluation->status,[ResearchResultStatus::Validated,ResearchResultStatus::PartiallyValidated],true)
            ?'PROMOTED':'REJECTED';
        $this->trading->saveCandidate($organizationId,$candidateId,$hypothesis->value,$candidateStatus,$candidatePayload);

        $riskAccepted=(bool)($risk['accepted']??false);
        $status=match(true){
            $evaluation->status===ResearchResultStatus::NotExecutable=>OpportunityStatus::Rejected,
            $evaluation->status===ResearchResultStatus::Rejected=>OpportunityStatus::Rejected,
            !$riskAccepted=>OpportunityStatus::Rejected,
            default=>OpportunityStatus::Approved,
        };
        $riskScore=min(100,max(0,100-$candidate->dataQualityScore+(count($risk['warnings']??[])*5)));
        $opportunityId='cm_opp_'.substr(hash('sha256',$candidateId.'|'.$strategyVersion),0,40);
        $executionProbability=DecimalMath::divide(Decimal::fromString((string)$candidate->dataQualityScore),Decimal::fromString('100'),6);
        $opportunity=new Opportunity(
            $opportunityId,$candidate,null,$economics->capitalRequired,$candidate->capitalCapacity,$executionProbability,
            $riskScore,$status,$now,
            [...$evaluation->reasons,...($risk['blocking_reasons']??[])],
            $type,$economics,$strategyVersion,
            array_values(array_unique(array_column($legs,'instrument_id'))),
            array_values(array_unique(array_column($legs,'venue_id'))),
            $evidence,
        );
        $riskId='cm_risk_'.substr(hash('sha256',$opportunityId.'|'.$now->format('U.u')),0,40);
        $riskAssessment=new RiskAssessment(
            $riskId,$opportunityId,$riskAccepted?RiskDecision::Approve:RiskDecision::Reject,$riskScore,
            $riskAccepted?$quantity:Decimal::fromString('0'),
            $riskAccepted?$economics->capitalRequired:Decimal::fromString('0'),
            $now,$risk['blocking_reasons']??[],$risk['warnings']??[],
        );
        $payload=[
            'id'=>$opportunityId,'candidate_id'=>$candidateId,'hypothesis'=>$hypothesis->value,'type'=>$type->value,
            'status'=>$status->value,'strategy_version'=>$strategyVersion,'validated_at'=>$now->format(DATE_ATOM),
            'expires_at'=>$expires->format(DATE_ATOM),'required_capital'=>$economics->capitalRequired->value(),
            'capital_capacity'=>$candidate->capitalCapacity->value(),'execution_probability'=>$executionProbability->value(),
            'risk_score'=>$riskScore,'expected_pnl'=>$economics->expectedNetPnl->value(),
            'expected_net_edge_bps'=>DecimalMath::basisPoints($economics->expectedNetPnl,$economics->capitalRequired,8)->value(),
            'economics'=>[
                'expected_basis_pnl_attribution'=>$economics->expectedBasisPnlAttribution->value(),
                'expected_funding_pnl'=>$economics->expectedFundingPnl->value(),
                'expected_price_neutralization_pnl'=>$economics->expectedPriceNeutralizationPnl->value(),
                'fees'=>$economics->fees->value(),'slippage'=>$economics->slippage->value(),'borrow'=>$economics->borrow->value(),
                'network_costs'=>$economics->networkCosts->value(),'risk_allowance'=>$economics->riskAllowance->value(),
                'expected_net_pnl'=>$economics->expectedNetPnl->value(),'holding_horizon_seconds'=>$economics->holdingHorizonSeconds,
                'basis_is_attribution_not_extra_profit'=>true,
            ],
            'risk'=>[
                'id'=>$riskId,'decision'=>$riskAssessment->decision->value,'risk_score'=>$riskScore,
                'approved_quantity'=>$riskAssessment->approvedQuantity->value(),
                'approved_notional'=>$riskAssessment->approvedNotional->value(),
                'blocking_reasons'=>$riskAssessment->blockingReasons,'warnings'=>$riskAssessment->warnings,
            ],
            'hedge_group_id'=>$hedge->id,'legs'=>$legs,'evidence'=>$evidence,
            'research_status'=>$evaluation->status->value,'research_reasons'=>$evaluation->reasons,
        ];
        $this->trading->saveOpportunity($organizationId,$opportunityId,$candidateId,$hypothesis->value,$status->value,$payload);
        $this->trading->saveRiskAssessment($organizationId,$riskId,$opportunityId,$riskAssessment->decision->value,$payload['risk']);
        $this->saveEvaluation($organizationId,$hypothesis,$marketPairId,$candidateId,$opportunityId,$status,$economics,$evaluation,$now);
        return $payload;
    }

    private function spotPerpHedge(MarketState $spot,MarketState $perp,Decimal $quantity,string $strategyVersion):HedgeGroup
    {
        return new HedgeGroup(
            'cm_hg_'.substr(hash('sha256',$spot->key().'|'.$perp->key().'|'.$strategyVersion.'|'.$spot->stateVersion.'|'.$perp->stateVersion),0,40),
            $strategyVersion,
            [
                new HedgeLeg($spot->instrumentId->value(),$spot->venueId->value(),PositionSide::Long,$quantity,Decimal::fromString('1'),Decimal::fromString('1')),
                new HedgeLeg($perp->instrumentId->value(),$perp->venueId->value(),PositionSide::Short,$quantity,Decimal::fromString('1'),Decimal::fromString('1')),
            ],
            Decimal::fromString('0'),Decimal::fromString('0.000001'),HedgeState::Planned,
        );
    }

    /** @return array<string,mixed> */
    private function leg(MarketState $state,PositionSide $side,Decimal $quantity,Decimal $price,string $kind):array
    {
        return [
            'instrument_id'=>$state->instrumentId->value(),'venue_id'=>$state->venueId->value(),'side'=>$side->value,
            'quantity'=>$quantity->value(),'execution_reference_price'=>$price->value(),'instrument_kind'=>$kind,
            'market_state_version'=>$state->stateVersion,
        ];
    }

    /** @return array{horizon:int,spot_fee:Decimal,perp_fee:Decimal,slippage_bps:Decimal,leverage:Decimal,risk_allowance:Decimal,network_costs:Decimal,emergency_buffer:Decimal} */
    private function commonEconomicsInputs(array $options):array
    {
        return [
            'horizon'=>$this->requiredInt($options,'holding_horizon_seconds'),
            'spot_fee'=>$this->requiredDecimal($options,'spot_fee_rate'),
            'perp_fee'=>$this->requiredDecimal($options,'perp_fee_rate'),
            'slippage_bps'=>$this->requiredDecimal($options,'round_trip_slippage_bps'),
            'leverage'=>$this->requiredDecimal($options,'leverage'),
            'risk_allowance'=>$this->requiredDecimal($options,'risk_allowance'),
            'network_costs'=>$this->decimal($options,'network_costs',Decimal::fromString('0')),
            'emergency_buffer'=>$this->requiredDecimal($options,'emergency_hedge_buffer'),
        ];
    }

    private function executableQuantity(MarketState $spot,MarketState $perp,Decimal $requested):Decimal
    {
        if($spot->bestQuote===null||$perp->bestQuote===null)return Decimal::fromString('0');
        $available=$spot->bestQuote->askQuantity->value->compareTo($perp->bestQuote->bidQuantity->value)<=0
            ?$spot->bestQuote->askQuantity->value:$perp->bestQuote->bidQuantity->value;
        return $requested->compareTo($available)<=0?$requested:$available;
    }

    private function fundingFromState(MarketState $state):FundingRateObservation
    {
        if($state->fundingRate===null||$state->fundingRate->eventType()!==MarketEventType::FundingRate){
            throw new DomainException('FUNDING_DATA_REQUIRED');
        }
        $a=$state->fundingRate->attributes;
        $interval=(int)($a['funding_interval_seconds']??0);
        if($interval<60)throw new DomainException('FUNDING_INTERVAL_REQUIRED');
        $next=$this->timestamp($a['next_settlement_at']??null);
        return new FundingRateObservation(
            $state->venueId,$state->instrumentId,$state->fundingRate->value,
            FundingRateType::tryFrom(strtoupper((string)($a['rate_type']??'VENUE_NATIVE')))??FundingRateType::VenueNative,
            $state->sourceTimestamp,$next,$interval,
            $this->optionalDecimal($a['cap']??null),$this->optionalDecimal($a['floor']??null),
            $state->sourceId->value(),$state->quality->score,
            FundingRateStatus::tryFrom(strtoupper((string)($a['status']??'UNKNOWN')))??FundingRateStatus::Unknown,
        );
    }

    private function economicallyRelated(string $organizationId,InstrumentId $a,InstrumentId $b,DateTimeImmutable $at):bool
    {
        if($a->equals($b))return true;
        $neighbors=static function(array $relationships,InstrumentId $self,DateTimeImmutable $at):array{
            $ids=[];
            foreach($relationships as $r){
                if(!$r instanceof EconomicRelationship||!$r->activeAt($at))continue;
                if($r->sourceInstrument->equals($self))$ids[$r->targetInstrument->value()]=true;
                if($r->targetInstrument->equals($self))$ids[$r->sourceInstrument->value()]=true;
            }
            return $ids;
        };
        $aNeighbors=$neighbors($this->relationships->forInstrument($organizationId,$a),$a,$at);
        if(isset($aNeighbors[$b->value()]))return true;
        $bNeighbors=$neighbors($this->relationships->forInstrument($organizationId,$b),$b,$at);
        return array_intersect_key($aNeighbors,$bNeighbors)!==[];
    }

    private function riskPolicy(array $options):DerivativesRiskPolicy
    {
        return new DerivativesRiskPolicy(
            $this->requiredDecimal($options,'maximum_adverse_basis_move_bps'),
            $this->requiredDecimal($options,'minimum_liquidation_distance'),
            $this->requiredInt($options,'maximum_unhedged_time_ms'),
            $this->requiredDecimal($options,'maximum_leverage'),
            $this->decimal($options,'maximum_net_delta',Decimal::fromString('0.000001')),
        );
    }

    private function liquidation(Decimal $mark,array $options,string $prefix):LiquidationRiskState
    {
        $distance=$this->optionalDecimal($options[$prefix.'liquidation_distance']??$options['liquidation_distance']??null);
        $price=$this->optionalDecimal($options[$prefix.'estimated_liquidation_price']??$options['estimated_liquidation_price']??null);
        $statusValue=strtoupper((string)($options[$prefix.'liquidation_status']??$options['liquidation_status']??'UNKNOWN'));
        $status=LiquidationRiskStatus::tryFrom($statusValue)??LiquidationRiskStatus::Unknown;
        return new LiquidationRiskState(
            $mark,$price,$distance,
            $this->optionalDecimal($options[$prefix.'margin_ratio']??$options['margin_ratio']??null),
            $this->optionalDecimal($options[$prefix.'maintenance_margin']??$options['maintenance_margin']??null),
            $status,(string)($options[$prefix.'liquidation_model_reference']??$options['liquidation_model_reference']??'PAPER_CONFIG'),
        );
    }

    private function saveScan(
        string $organizationId,HypothesisCode $hypothesis,string $marketPairId,DateTimeImmutable $at,
        bool $observable,bool $detected,?string $reason,
    ):void{
        $fingerprint=hash('sha256',implode('|',[$organizationId,$hypothesis->value,'SCAN',$marketPairId,$at->format('U.u')]));
        $this->trading->saveHypothesisObservation(
            $organizationId,'cm_obs_'.substr($fingerprint,0,40),$hypothesis->value,'SCAN',$at->format(DATE_ATOM),$fingerprint,
            ['market_pair_id'=>$marketPairId,'observable'=>$observable,'detected'=>$detected,'executable'=>false,'realized'=>false,
             'expected_pnl'=>'0','realized_pnl'=>'0','reason'=>$reason]
        );
    }

    private function saveEvaluation(
        string $organizationId,HypothesisCode $hypothesis,string $marketPairId,string $candidateId,string $opportunityId,
        OpportunityStatus $status,ExpectedEconomics $economics,RelativeValueEvaluation $evaluation,DateTimeImmutable $at,
    ):void{
        $fingerprint=hash('sha256',implode('|',[$organizationId,$hypothesis->value,'EVALUATION',$candidateId,$opportunityId]));
        $this->trading->saveHypothesisObservation(
            $organizationId,'cm_obs_'.substr($fingerprint,0,40),$hypothesis->value,'EVALUATION',$at->format(DATE_ATOM),$fingerprint,
            ['market_pair_id'=>$marketPairId,'candidate_id'=>$candidateId,'opportunity_id'=>$opportunityId,'detected'=>true,
             'executable'=>$status===OpportunityStatus::Approved,'realized'=>false,'expected_pnl'=>$economics->expectedNetPnl->value(),
             'realized_pnl'=>'0','reason'=>$evaluation->reasons===[]?null:implode(',',$evaluation->reasons),
             'research_status'=>$evaluation->status->value]
        );
    }

    /** @param list<Decimal> $values */
    private function minimum(array $values):Decimal
    {
        if($values===[])throw new InvalidArgumentException('Minimum requires at least one Decimal.');
        $min=$values[0];
        foreach($values as $value){
            if(!$value instanceof Decimal)throw new InvalidArgumentException('Minimum values must be Decimal.');
            if($value->compareTo($min)<0)$min=$value;
        }
        return $min;
    }

    private function int(array $options,string $key,int $default):int
    {
        return array_key_exists($key,$options)?(int)$options[$key]:$default;
    }

    private function qualityFromRisk(array $risk):int
    {
        $penalty=count($risk['blocking_reasons']??[])*20+count($risk['warnings']??[])*5;
        return max(0,min(100,100-$penalty));
    }

    private function timestamp(mixed $value):?DateTimeImmutable
    {
        if($value===null||$value==='')return null;
        if((is_string($value)||is_int($value))&&ctype_digit((string)$value)){
            $raw=(string)$value;$seconds=strlen($raw)>10?intdiv((int)$raw,1000):(int)$raw;
            return (new DateTimeImmutable())->setTimestamp($seconds);
        }
        return is_string($value)?new DateTimeImmutable($value):null;
    }

    private function optionalDecimal(mixed $value):?Decimal
    {
        return $value===null||$value===''?null:Decimal::fromString((string)$value);
    }
    private function requiredDecimal(array $options,string $key):Decimal
    {
        if(!array_key_exists($key,$options))throw new InvalidArgumentException($key.' is required.');
        return Decimal::fromString((string)$options[$key]);
    }
    private function decimal(array $options,string $key,Decimal $default):Decimal
    {
        return array_key_exists($key,$options)?Decimal::fromString((string)$options[$key]):$default;
    }
    private function requiredInt(array $options,string $key):int
    {
        if(!array_key_exists($key,$options))throw new InvalidArgumentException($key.' is required.');
        $value=(int)$options[$key];if($value<1)throw new InvalidArgumentException($key.' must be positive.');return $value;
    }
    private function bool(array $options,string $key,bool $default):bool
    {
        if(!array_key_exists($key,$options))return $default;
        return filter_var($options[$key],FILTER_VALIDATE_BOOL,FILTER_NULL_ON_FAILURE)??$default;
    }
}
