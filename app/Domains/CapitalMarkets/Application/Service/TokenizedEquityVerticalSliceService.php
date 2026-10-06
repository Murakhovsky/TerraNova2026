<?php
declare(strict_types=1);

namespace Domains\CapitalMarkets\Application\Service;

use DateTimeImmutable;
use DomainException;
use Domains\CapitalMarkets\Application\Contract\MarketStateRepositoryInterface;
use Domains\CapitalMarkets\Application\Contract\TokenizedEquityVerticalSliceRepositoryInterface;
use Domains\CapitalMarkets\Domain\Contract\RelationshipRepository;
use Domains\CapitalMarkets\Domain\Instrument\EconomicRelationship;
use Domains\CapitalMarkets\Domain\Instrument\EconomicRelationshipStrength;
use Domains\CapitalMarkets\Domain\Instrument\InstrumentId;
use Domains\CapitalMarkets\Domain\MarketData\MarketSourceId;
use Domains\CapitalMarkets\Domain\Opportunity\HypothesisCode;
use Domains\CapitalMarkets\Domain\Opportunity\Opportunity;
use Domains\CapitalMarkets\Domain\Opportunity\OpportunityStatus;
use Domains\CapitalMarkets\Domain\Opportunity\SpreadCandidate;
use Domains\CapitalMarkets\Domain\Opportunity\SpreadDetectorConfig;
use Domains\CapitalMarkets\Domain\Risk\RiskAssessment;
use Domains\CapitalMarkets\Domain\Service\NetEconomicsEngine;
use Domains\CapitalMarkets\Domain\Service\TokenizedEquityRiskEngine;
use Domains\CapitalMarkets\Domain\Service\TokenizedEquitySpreadDetector;
use Domains\CapitalMarkets\Domain\Value\Decimal;
use Domains\CapitalMarkets\Domain\Value\DecimalMath;
use Domains\CapitalMarkets\Domain\Venue\VenueId;
use InvalidArgumentException;

final readonly class TokenizedEquityVerticalSliceService
{
    public function __construct(
        private MarketStateRepositoryInterface $marketStates,
        private TokenizedEquityVerticalSliceRepositoryInterface $repository,
        private RelationshipRepository $relationships,
        private TokenizedEquitySpreadDetector $detector,
        private TrustedConversionRateResolver $conversionRates,
        private NetEconomicsEngine $economics,
        private TokenizedEquityRiskEngine $risk,
        private TokenizedEquityTelemetry $telemetry,
    ){}

    /** @param array<string,mixed> $options @return array<string,mixed> */
    public function scanCrossVenue(
        string $organizationId,
        string $marketPairId,
        string $venueA,
        string $instrumentA,
        string $venueB,
        string $instrumentB,
        array $options,
    ):array{
        $now=new DateTimeImmutable();
        $this->telemetry->metric($organizationId,'spread_detector_runs_total',1.0,['hypothesis'=>'H2']);
        $config=$this->config($options);
        $ttlMs=$this->int($options,'ttl_ms',1000);
        $aInstrumentId=InstrumentId::fromString($instrumentA);
        $bInstrumentId=InstrumentId::fromString($instrumentB);
        $this->assertEconomicEquivalence($organizationId,$aInstrumentId,$bInstrumentId,$config,$now);

        $a=$this->marketStates->get($organizationId,VenueId::fromString($venueA),$aInstrumentId);
        $b=$this->marketStates->get($organizationId,VenueId::fromString($venueB),$bInstrumentId);
        $issues=[];
        if($a===null)$issues[]='STATE_A_UNAVAILABLE';
        if($b===null)$issues[]='STATE_B_UNAVAILABLE';
        if($issues!==[]){
            $this->saveScanObservation(
                $organizationId,HypothesisCode::CrossVenueTokenizedEquityArbitrage,$marketPairId,$now,false,false,$issues
            );
            return ['hypothesis'=>'H2','candidate_count'=>0,'opportunities'=>[],'observation_issues'=>$issues];
        }

        $issues=$this->detector->crossVenueObservationIssues($a,$b,$config,$now,$ttlMs);
        $candidates=$this->detector->detectCrossVenue($marketPairId,$a,$b,$config,$now,$ttlMs);
        $this->saveScanObservation(
            $organizationId,HypothesisCode::CrossVenueTokenizedEquityArbitrage,$marketPairId,$now,
            $issues===[],$candidates!==[],$issues
        );
        return $this->evaluate($organizationId,$candidates,$config,$options,$now,true);
    }

    /** @param array<string,mixed> $options @return array<string,mixed> */
    public function scanReference(
        string $organizationId,
        string $marketPairId,
        string $referenceSource,
        string $underlyingInstrument,
        string $tokenVenue,
        string $tokenInstrument,
        array $options,
    ):array{
        $now=new DateTimeImmutable();
        $this->telemetry->metric($organizationId,'spread_detector_runs_total',1.0,['hypothesis'=>'H1']);
        $config=$this->config($options);
        $ttlMs=$this->int($options,'ttl_ms',1000);
        $underlyingId=InstrumentId::fromString($underlyingInstrument);
        $tokenId=InstrumentId::fromString($tokenInstrument);
        $this->assertEconomicEquivalence($organizationId,$underlyingId,$tokenId,$config,$now);

        $reference=$this->marketStates->getReference(
            $organizationId,MarketSourceId::fromString($referenceSource),$underlyingId
        );
        $token=$this->marketStates->get(
            $organizationId,VenueId::fromString($tokenVenue),$tokenId
        );
        $issues=[];
        if($reference===null)$issues[]='REFERENCE_STATE_UNAVAILABLE';
        if($token===null)$issues[]='TOKEN_STATE_UNAVAILABLE';
        if($issues!==[]){
            $this->saveScanObservation(
                $organizationId,HypothesisCode::TokenizedEquityDislocation,$marketPairId,$now,false,false,$issues
            );
            return ['hypothesis'=>'H1','candidate_count'=>0,'opportunities'=>[],'observation_issues'=>$issues];
        }

        $conversion=null;
        if($reference->currentQuote!==null&&$token->bestQuote!==null){
            $referenceQuote=$reference->currentQuote->askPrice->quoteAsset;
            $tokenQuote=$token->bestQuote->askPrice->quoteAsset;
            if(!$referenceQuote->equals($tokenQuote)){
                $conversion=$this->conversionRates->resolve(
                    $organizationId,$tokenQuote,$referenceQuote,$now,$config->maximumSnapshotAgeMs
                );
            }
        }
        $issues=$this->detector->referenceObservationIssues($reference,$token,$config,$now,$ttlMs,$conversion);
        $candidates=$this->detector->detectReferenceDislocation(
            $marketPairId,$reference,$token,$config,$now,$ttlMs,$conversion
        );

        $this->saveScanObservation(
            $organizationId,HypothesisCode::TokenizedEquityDislocation,$marketPairId,$now,
            $issues===[],$candidates!==[],$issues
        );

        $hedgeVenue=trim((string)($options['hedge_venue_id']??''));
        $hedgeInstrument=trim((string)($options['hedge_instrument_id']??''));
        if(($hedgeVenue==='')!==($hedgeInstrument==='')){
            throw new InvalidArgumentException('hedge_venue_id and hedge_instrument_id must be supplied together.');
        }
        if($hedgeVenue===''||$hedgeInstrument===''){
            // Research-only H1 remains fail-closed when no executable hedge venue is supplied.
            return $this->evaluate($organizationId,$candidates,$config,$options,$now,false);
        }

        $hedgeId=InstrumentId::fromString($hedgeInstrument);
        $this->assertEconomicEquivalence($organizationId,$underlyingId,$hedgeId,$config,$now);
        $hedge=$this->marketStates->get($organizationId,VenueId::fromString($hedgeVenue),$hedgeId);
        if($hedge===null){
            return $this->evaluate($organizationId,$candidates,$config,$options,$now,false);
        }
        $hedgeIssues=$this->detector->crossVenueObservationIssues($token,$hedge,$config,$now,$ttlMs);
        if($hedgeIssues!==[]){
            return $this->evaluate($organizationId,$candidates,$config,$options,$now,false);
        }

        $executableCandidates=$this->repriceH1Candidates($candidates,$token,$hedge);
        return $this->evaluate($organizationId,$executableCandidates,$config,$options,$now,true);
    }

    /**
     * @param list<SpreadCandidate> $candidates
     * @return list<SpreadCandidate>
     */
    private function repriceH1Candidates(
        array $candidates,
        \Domains\CapitalMarkets\Domain\MarketData\MarketState $token,
        \Domains\CapitalMarkets\Domain\MarketData\MarketState $hedge,
    ):array{
        if($token->bestQuote===null||$hedge->bestQuote===null)return [];
        $out=[];
        foreach($candidates as $candidate){
            $buyIsReference=str_starts_with($candidate->buyVenueId,'reference:');
            $sellIsReference=str_starts_with($candidate->sellVenueId,'reference:');
            if(!$buyIsReference&&!$sellIsReference){
                $out[]=$candidate;
                continue;
            }

            if($buyIsReference){
                $buyVenue=$hedge->venueId->value();
                $buyInstrument=$hedge->instrumentId->value();
                $buyPrice=$hedge->bestQuote->askPrice->value;
                $buyQuantity=$hedge->bestQuote->askQuantity->value;
                $sellVenue=$token->venueId->value();
                $sellInstrument=$token->instrumentId->value();
                $sellPrice=$token->bestQuote->bidPrice->value;
                $sellQuantity=$token->bestQuote->bidQuantity->value;
            }else{
                $buyVenue=$token->venueId->value();
                $buyInstrument=$token->instrumentId->value();
                $buyPrice=$token->bestQuote->askPrice->value;
                $buyQuantity=$token->bestQuote->askQuantity->value;
                $sellVenue=$hedge->venueId->value();
                $sellInstrument=$hedge->instrumentId->value();
                $sellPrice=$hedge->bestQuote->bidPrice->value;
                $sellQuantity=$hedge->bestQuote->bidQuantity->value;
            }

            $quantity=$buyQuantity->compareTo($sellQuantity)<=0?$buyQuantity:$sellQuantity;
            if(!$quantity->isPositive())continue;
            $spread=DecimalMath::subtract($sellPrice,$buyPrice);
            $edgeBps=DecimalMath::basisPoints($spread,$buyPrice,6);
            $capacity=DecimalMath::multiply($buyPrice,$quantity);
            $id='cm_candidate_'.hash('sha256',implode('|',[
                $candidate->id,'hedge',$hedge->venueId->value(),$hedge->instrumentId->value(),
                (string)$hedge->stateVersion,$buyPrice->value(),$sellPrice->value(),
            ]));
            $out[]=new SpreadCandidate(
                $id,
                HypothesisCode::TokenizedEquityDislocation,
                $candidate->marketPairId,
                $candidate->direction,
                $candidate->detectedAt,
                $candidate->expiresAt,
                $buyVenue,
                $sellVenue,
                $buyInstrument,
                $sellInstrument,
                $buyPrice,
                $sellPrice,
                $quantity,
                $spread,
                $edgeBps,
                $capacity,
                min($candidate->dataQualityScore,$hedge->quality->score),
                [
                    ...$candidate->evidence,
                    'theoretical_candidate_id'=>$candidate->id,
                    'theoretical_buy_price'=>$candidate->buyPrice->value(),
                    'theoretical_sell_price'=>$candidate->sellPrice->value(),
                    'theoretical_gross_edge_bps'=>$candidate->grossEdgeBps->value(),
                    'hedge_venue_id'=>$hedge->venueId->value(),
                    'hedge_instrument_id'=>$hedge->instrumentId->value(),
                    'hedge_state_version'=>$hedge->stateVersion,
                ],
            );
        }
        return $out;
    }

    /** @return list<array<string,mixed>> */
    public function opportunities(string $organizationId,int $limit=200):array
    {
        return $this->repository->listOpportunities($organizationId,$limit);
    }

    /** @return array<string,mixed> */
    public function dashboard(string $organizationId):array
    {
        return $this->repository->dashboard($organizationId);
    }

    /**
     * @param list<SpreadCandidate> $candidates
     * @param array<string,mixed> $options
     * @return array<string,mixed>
     */
    private function evaluate(
        string $organizationId,
        array $candidates,
        SpreadDetectorConfig $config,
        array $options,
        DateTimeImmutable $now,
        bool $hedgeAvailable,
    ):array{
        $out=[];
        foreach($candidates as $candidate){
            $candidatePayload=$this->candidateArray($candidate);
            $buyFeeRate=$this->requiredDecimal($options,'buy_fee_rate');
            $sellFeeRate=$this->requiredDecimal($options,'sell_fee_rate');
            $quantity=$this->decimal($options,'quantity',$candidate->quantity);
            if($quantity->compareTo($candidate->quantity)>0)$quantity=$candidate->quantity;

            $estimate=$this->economics->estimate(
                $candidate->buyPrice,$candidate->sellPrice,$quantity,
                $buyFeeRate,$sellFeeRate,
                $this->decimal($options,'buy_slippage_cost',Decimal::fromString('0')),
                $this->decimal($options,'sell_slippage_cost',Decimal::fromString('0')),
                $this->decimal($options,'fx_cost',Decimal::fromString('0')),
                $this->decimal($options,'hedge_cost',Decimal::fromString('0')),
                $this->decimal($options,'financing_cost',Decimal::fromString('0')),
                $this->decimal($options,'network_cost',Decimal::fromString('0')),
                $this->decimal($options,'settlement_cost',Decimal::fromString('0')),
                $this->decimal($options,'execution_risk_allowance',Decimal::fromString('0')),
            );

            $passesEconomics=$estimate->expectedNetPnl->compareTo($config->minimumExpectedPnl)>=0
                &&$estimate->expectedNetEdgeBps->compareTo($config->minimumExpectedNetEdgeBps)>=0;

            $candidateStatus=$passesEconomics?'PROMOTED':'REJECTED';
            $candidatePayload['status']=$candidateStatus;
            if(!$passesEconomics)$candidatePayload['rejection_reason']='NO_EXECUTABLE_EDGE';
            $this->repository->saveCandidate(
                $organizationId,$candidate->id,$candidate->hypothesis->value,$candidateStatus,$candidatePayload
            );

            $opportunityId='cm_opp_'.substr(hash('sha256',$candidate->id.'|'.$config->version),0,40);
            $riskScore=min(100,max(0,20+(100-$candidate->dataQualityScore)));
            $opportunity=new Opportunity(
                $opportunityId,$candidate,$estimate,$estimate->requiredCapital,$candidate->capitalCapacity,
                \Domains\CapitalMarkets\Domain\Value\DecimalMath::divide(
                    Decimal::fromString((string)max(0,min(100,$candidate->dataQualityScore))),Decimal::fromString('100'),6
                ),
                $riskScore,$passesEconomics?OpportunityStatus::Valid:OpportunityStatus::Rejected,$now,
                $passesEconomics?[]:['NO_EXECUTABLE_EDGE'],
            );

            $risk=$this->risk->assess(
                $opportunity,$quantity,
                $this->decimal($options,'max_trade_notional',$candidate->capitalCapacity),
                $this->int($options,'max_risk_score',70),$now,$hedgeAvailable,
                $this->bool($options,'kill_switch',false),
                $config->minimumExecutionProbability,
            );

            $finalStatus=$passesEconomics&&$risk->approved()?OpportunityStatus::Approved:OpportunityStatus::Rejected;
            $reasons=[...$opportunity->rejectionReasons,...$risk->blockingReasons];
            $payload=$this->opportunityArray($opportunity,$risk,$finalStatus,$reasons,$buyFeeRate,$sellFeeRate,$quantity);
            $this->repository->saveOpportunity(
                $organizationId,$opportunityId,$candidate->id,$candidate->hypothesis->value,$finalStatus->value,$payload
            );
            $this->repository->saveRiskAssessment(
                $organizationId,$risk->id,$opportunityId,$risk->decision->value,$this->riskArray($risk)
            );
            $this->saveEvaluationObservation(
                $organizationId,
                $candidate,
                $opportunityId,
                $finalStatus===OpportunityStatus::Approved,
                $estimate->expectedNetPnl->value(),
                $reasons,
                $now
            );
            $this->telemetry->metric($organizationId,'opportunity_evaluations_total',1.0,[
                'hypothesis'=>$candidate->hypothesis->value,
                'status'=>$finalStatus->value,
            ]);
            $out[]=$payload;
        }

        $this->telemetry->metric($organizationId,'spread_candidates_total',(float)count($candidates),[
            'hypothesis'=>$candidates===[]?'NONE':$candidates[0]->hypothesis->value,
        ]);
        return [
            'hypothesis'=>$candidates===[]?null:$candidates[0]->hypothesis->value,
            'candidate_count'=>count($candidates),
            'opportunities'=>$out,
        ];
    }


    /** @param list<string> $issues */
    private function saveScanObservation(
        string $organizationId,
        HypothesisCode $hypothesis,
        string $marketPairId,
        DateTimeImmutable $observedAt,
        bool $observable,
        bool $detected,
        array $issues=[],
    ):void{
        $fingerprint=hash('sha256',implode('|',[
            $organizationId,$hypothesis->value,'SCAN',$marketPairId,$observedAt->format('Y-m-d\\TH:i:s.uP'),
        ]));
        $id='cm_obs_'.substr($fingerprint,0,40);
        $this->telemetry->metric($organizationId,'market_snapshots_total',1.0,['hypothesis'=>$hypothesis->value,'observable'=>$observable]);
        if(!$observable){
            $this->telemetry->metric($organizationId,'unobservable_scans_total',1.0,['hypothesis'=>$hypothesis->value]);
        }
        $this->repository->saveHypothesisObservation(
            $organizationId,$id,$hypothesis->value,'SCAN',$observedAt->format(DATE_ATOM),$fingerprint,[
                'market_pair_id'=>$marketPairId,
                'observable'=>$observable,
                'detected'=>$detected,
                'executable'=>false,
                'realized'=>false,
                'expected_pnl'=>'0',
                'realized_pnl'=>'0',
                'reason'=>$observable
                    ?($detected?null:'NO_DETECTED_CANDIDATE')
                    :implode(',',array_values(array_unique($issues))),
            ]
        );
    }

    /** @param list<string> $reasons */
    private function saveEvaluationObservation(
        string $organizationId,
        SpreadCandidate $candidate,
        string $opportunityId,
        bool $executable,
        string $expectedPnl,
        array $reasons,
        DateTimeImmutable $observedAt,
    ):void{
        $fingerprint=hash('sha256',implode('|',[
            $organizationId,$candidate->hypothesis->value,'EVALUATION',$candidate->id,$opportunityId,
        ]));
        $id='cm_obs_'.substr($fingerprint,0,40);
        $this->repository->saveHypothesisObservation(
            $organizationId,$id,$candidate->hypothesis->value,'EVALUATION',$observedAt->format(DATE_ATOM),$fingerprint,[
                'market_pair_id'=>$candidate->marketPairId,
                'candidate_id'=>$candidate->id,
                'opportunity_id'=>$opportunityId,
                'detected'=>true,
                'executable'=>$executable,
                'realized'=>false,
                'expected_pnl'=>$expectedPnl,
                'realized_pnl'=>'0',
                'reason'=>$reasons===[]?null:implode(',',array_values(array_unique($reasons))),
            ]
        );
    }

    /** @return array<string,mixed> */
    private function candidateArray(SpreadCandidate $candidate):array
    {
        return [
            'id'=>$candidate->id,'hypothesis'=>$candidate->hypothesis->value,'market_pair_id'=>$candidate->marketPairId,
            'direction'=>$candidate->direction->value,'detected_at'=>$candidate->detectedAt->format(DATE_ATOM),
            'expires_at'=>$candidate->expiresAt->format(DATE_ATOM),'buy_venue_id'=>$candidate->buyVenueId,
            'sell_venue_id'=>$candidate->sellVenueId,'buy_instrument_id'=>$candidate->buyInstrumentId,
            'sell_instrument_id'=>$candidate->sellInstrumentId,'buy_price'=>$candidate->buyPrice->value(),
            'sell_price'=>$candidate->sellPrice->value(),'quantity'=>$candidate->quantity->value(),
            'gross_spread'=>$candidate->grossSpread->value(),'gross_edge_bps'=>$candidate->grossEdgeBps->value(),
            'capital_capacity'=>$candidate->capitalCapacity->value(),'data_quality_score'=>$candidate->dataQualityScore,
            'evidence'=>$candidate->evidence,
        ];
    }

    /** @return array<string,mixed> */
    private function opportunityArray(
        Opportunity $opportunity,RiskAssessment $risk,OpportunityStatus $status,array $reasons,
        Decimal $buyFeeRate,Decimal $sellFeeRate,Decimal $quantity,
    ):array{
        return [
            'id'=>$opportunity->id,'candidate'=>$this->candidateArray($opportunity->candidate),
            'hypothesis'=>$opportunity->candidate->hypothesis->value,'status'=>$status->value,
            'validated_at'=>$opportunity->validatedAt->format(DATE_ATOM),'expires_at'=>$opportunity->candidate->expiresAt->format(DATE_ATOM),
            'gross_pnl'=>$opportunity->economics->grossPnl->value(),'total_cost'=>$opportunity->economics->totalCost->value(),
            'expected_pnl'=>$opportunity->economics->expectedNetPnl->value(),
            'expected_net_edge_bps'=>$opportunity->economics->expectedNetEdgeBps->value(),
            'required_capital'=>$opportunity->requiredCapital->value(),'capital_capacity'=>$opportunity->capitalCapacity->value(),
            'execution_probability'=>$opportunity->executionProbability->value(),'risk_score'=>$opportunity->riskScore,
            'risk'=>$this->riskArray($risk),'rejection_reasons'=>array_values(array_unique($reasons)),
            'execution_parameters'=>[
                'quantity'=>$quantity->value(),'buy_fee_rate'=>$buyFeeRate->value(),'sell_fee_rate'=>$sellFeeRate->value(),
            ],
        ];
    }

    /** @return array<string,mixed> */
    private function riskArray(RiskAssessment $risk):array
    {
        return [
            'id'=>$risk->id,'decision'=>$risk->decision->value,'risk_score'=>$risk->riskScore,
            'approved_quantity'=>$risk->approvedQuantity->value(),'approved_notional'=>$risk->approvedNotional->value(),
            'assessed_at'=>$risk->assessedAt->format(DATE_ATOM),'blocking_reasons'=>$risk->blockingReasons,'warnings'=>$risk->warnings,
        ];
    }

    private function assertEconomicEquivalence(
        string $organizationId,
        InstrumentId $left,
        InstrumentId $right,
        SpreadDetectorConfig $config,
        DateTimeImmutable $at,
    ):void{
        if($left->equals($right))return;
        $matches=array_filter(
            $this->relationships->forInstrument($organizationId,$left),
            static fn(EconomicRelationship $r):bool =>
                $r->activeAt($at)
                && (($r->sourceInstrument->equals($left)&&$r->targetInstrument->equals($right))
                    ||($r->sourceInstrument->equals($right)&&$r->targetInstrument->equals($left)))
        );
        if($matches===[])throw new DomainException('ECONOMIC_RELATIONSHIP_REQUIRED');

        $best=Decimal::fromString('0');
        foreach($matches as $relationship){
            $metadataScore=$relationship->metadata['economic_equivalence_score']??null;
            $score=$metadataScore!==null
                ? Decimal::fromString((string)$metadataScore)
                : match($relationship->strength){
                    EconomicRelationshipStrength::Exact=>Decimal::fromString('1'),
                    EconomicRelationshipStrength::Direct=>Decimal::fromString('0.9'),
                    EconomicRelationshipStrength::Derived=>Decimal::fromString('0.7'),
                    EconomicRelationshipStrength::Statistical=>Decimal::fromString('0.3'),
                };
            if($score->compareTo($best)>0)$best=$score;
        }
        if($best->compareTo($config->economicEquivalenceThreshold)<0){
            throw new DomainException('ECONOMIC_EQUIVALENCE_BELOW_THRESHOLD');
        }
    }

    /** @param array<string,mixed> $options */
    private function config(array $options):SpreadDetectorConfig
    {
        return new SpreadDetectorConfig(
            trim((string)($options['config_version']??'tokenized-equity-v1')),
            $this->decimal($options,'minimum_gross_edge_bps',Decimal::fromString('1')),
            $this->decimal($options,'minimum_expected_net_edge_bps',Decimal::fromString('1')),
            $this->decimal($options,'minimum_expected_pnl',Decimal::fromString('0.01')),
            $this->int($options,'maximum_snapshot_age_ms',2000),
            $this->int($options,'maximum_snapshot_skew_ms',500),
            $this->int($options,'minimum_data_quality',80),
            $this->decimal($options,'maximum_slippage_bps',Decimal::fromString('50')),
            $this->int($options,'minimum_opportunity_ttl_ms',500),
            $this->decimal($options,'economic_equivalence_threshold',Decimal::fromString('0.8')),
            $this->decimal($options,'minimum_execution_probability',Decimal::fromString('0')),
        );
    }

    /** @param array<string,mixed> $options */
    private function requiredDecimal(array $options,string $key):Decimal
    {
        if(!array_key_exists($key,$options))throw new InvalidArgumentException($key.' is required; zero-cost trading may not be assumed.');
        return Decimal::fromString((string)$options[$key]);
    }

    /** @param array<string,mixed> $options */
    private function decimal(array $options,string $key,Decimal $default):Decimal
    {
        return array_key_exists($key,$options)?Decimal::fromString((string)$options[$key]):$default;
    }

    /** @param array<string,mixed> $options */
    private function int(array $options,string $key,int $default):int
    {
        return array_key_exists($key,$options)?(int)$options[$key]:$default;
    }

    /** @param array<string,mixed> $options */
    private function bool(array $options,string $key,bool $default):bool
    {
        if(!array_key_exists($key,$options))return $default;
        return filter_var($options[$key],FILTER_VALIDATE_BOOL,FILTER_NULL_ON_FAILURE)??$default;
    }
}
