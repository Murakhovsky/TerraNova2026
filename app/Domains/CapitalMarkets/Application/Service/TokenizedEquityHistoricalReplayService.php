<?php
declare(strict_types=1);

namespace Domains\CapitalMarkets\Application\Service;

use Domains\CapitalMarkets\Application\Contract\MarketSnapshotRepositoryInterface;
use Domains\CapitalMarkets\Domain\Contract\RelationshipRepository;
use Domains\CapitalMarkets\Domain\Instrument\EconomicRelationship;
use Domains\CapitalMarkets\Domain\Instrument\EconomicRelationshipStrength;
use Domains\CapitalMarkets\Domain\MarketData\ConversionRate;
use Domains\CapitalMarkets\Domain\Value\DecimalMath;
use Domains\CapitalMarkets\Domain\MarketData\MarketSnapshot;
use Domains\CapitalMarkets\Domain\Opportunity\SpreadDetectorConfig;
use Domains\CapitalMarkets\Domain\Service\NetEconomicsEngine;
use Domains\CapitalMarkets\Domain\Service\TokenizedEquitySpreadDetector;
use Domains\CapitalMarkets\Domain\Value\Decimal;
use InvalidArgumentException;

final readonly class TokenizedEquityHistoricalReplayService
{
    public function __construct(
        private MarketSnapshotRepositoryInterface $snapshots,
        private TokenizedEquitySpreadDetector $detector,
        private NetEconomicsEngine $economics,
        private RelationshipRepository $relationships,
    ){}

    /** @param array<string,mixed> $options @return array<string,mixed> */
    public function replay(string $organizationId,array $options):array
    {
        $config=$this->config($options);
        $ttlMs=$this->int($options,'ttl_ms',1000);
        $buyFee=$this->requiredDecimal($options,'buy_fee_rate');
        $sellFee=$this->requiredDecimal($options,'sell_fee_rate');
        $buySlippageBps=$this->decimal($options,'buy_slippage_bps','0');
        $sellSlippageBps=$this->decimal($options,'sell_slippage_bps','0');
        $snapshots=$this->snapshots->list($organizationId,$this->int($options,'limit',500));

        $observations=[];$detected=0;$executable=0;
        foreach($snapshots as $snapshot){
            if(!$snapshot instanceof MarketSnapshot)continue;
            $at=$snapshot->createdAt;
            $states=$snapshot->instrumentStates;
            $references=$snapshot->referenceStates;

            $stateCount=count($states);
            for($i=0;$i<$stateCount;$i++){
                for($j=$i+1;$j<$stateCount;$j++){
                    $a=$states[$i];$b=$states[$j];
                    if($a->venueId->equals($b->venueId))continue;
                    if(!$a->instrumentId->equals($b->instrumentId))continue;
                    $pair='replay:h2:'.$a->instrumentId->value();
                    $issues=$this->detector->crossVenueObservationIssues($a,$b,$config,$at,$ttlMs);
                    $candidates=$this->detector->detectCrossVenue($pair,$a,$b,$config,$at,$ttlMs);
                    foreach($candidates as $candidate){
                        $detected++;
                        $estimate=$this->economics->estimate(
                            $candidate->buyPrice,$candidate->sellPrice,$candidate->quantity,$buyFee,$sellFee,
                            $this->slippageCost($candidate->buyPrice,$candidate->quantity,$buySlippageBps),
                            $this->slippageCost($candidate->sellPrice,$candidate->quantity,$sellSlippageBps),
                            Decimal::fromString('0'),
                            Decimal::fromString('0'),Decimal::fromString('0'),Decimal::fromString('0'),
                            Decimal::fromString('0'),Decimal::fromString('0')
                        );
                        $isExecutable=$estimate->expectedNetPnl->compareTo($config->minimumExpectedPnl)>=0
                            &&$estimate->expectedNetEdgeBps->compareTo($config->minimumExpectedNetEdgeBps)>=0;
                        if($isExecutable)$executable++;
                        $observations[]=[
                            'snapshot_id'=>$snapshot->snapshotId,'hypothesis'=>'H2','observable'=>$issues===[],
                            'candidate_id'=>$candidate->id,'gross_edge_bps'=>$candidate->grossEdgeBps->value(),
                            'expected_net_edge_bps'=>$estimate->expectedNetEdgeBps->value(),
                            'expected_pnl'=>$estimate->expectedNetPnl->value(),'executable'=>$isExecutable,'issues'=>$issues,
                        ];
                    }
                    if($candidates===[])$observations[]=[
                        'snapshot_id'=>$snapshot->snapshotId,'hypothesis'=>'H2','observable'=>$issues===[],
                        'candidate_id'=>null,'executable'=>false,'issues'=>$issues,
                    ];
                }
            }

            foreach($references as $reference){
                foreach($states as $token){
                    if($reference->instrumentId->equals($token->instrumentId))continue;
                    if(!$this->relationshipValid(
                        $organizationId,$reference->instrumentId,$token->instrumentId,$config,$at
                    ))continue;

                    $pair='replay:h1:'.$reference->instrumentId->value().':'.$token->instrumentId->value().':'.$token->venueId->value();
                    try{
                        $conversion=$this->snapshotConversion($snapshot,$reference,$token);
                        $issues=$this->detector->referenceObservationIssues($reference,$token,$config,$at,$ttlMs,$conversion);
                        $candidates=$this->detector->detectReferenceDislocation($pair,$reference,$token,$config,$at,$ttlMs,$conversion);
                        foreach($candidates as $candidate){
                            $detected++;
                            $estimate=$this->economics->estimate(
                                $candidate->buyPrice,$candidate->sellPrice,$candidate->quantity,$buyFee,$sellFee,
                                $this->slippageCost($candidate->buyPrice,$candidate->quantity,$buySlippageBps),
                                $this->slippageCost($candidate->sellPrice,$candidate->quantity,$sellSlippageBps),
                                Decimal::fromString('0'),
                                Decimal::fromString('0'),Decimal::fromString('0'),Decimal::fromString('0'),
                                Decimal::fromString('0'),Decimal::fromString('0')
                            );
                            $isExecutable=$estimate->expectedNetPnl->compareTo($config->minimumExpectedPnl)>=0
                                &&$estimate->expectedNetEdgeBps->compareTo($config->minimumExpectedNetEdgeBps)>=0;
                            if($isExecutable)$executable++;
                            $observations[]=[
                                'snapshot_id'=>$snapshot->snapshotId,'hypothesis'=>'H1','observable'=>$issues===[],
                                'candidate_id'=>$candidate->id,'gross_edge_bps'=>$candidate->grossEdgeBps->value(),
                                'expected_net_edge_bps'=>$estimate->expectedNetEdgeBps->value(),
                                'expected_pnl'=>$estimate->expectedNetPnl->value(),'executable'=>$isExecutable,'issues'=>$issues,
                            ];
                        }
                        if($candidates===[])$observations[]=[
                            'snapshot_id'=>$snapshot->snapshotId,'hypothesis'=>'H1','observable'=>$issues===[],
                            'candidate_id'=>null,'executable'=>false,'issues'=>$issues,
                        ];
                    }catch(\DomainException $error){
                        $observations[]=[
                            'snapshot_id'=>$snapshot->snapshotId,'hypothesis'=>'H1','observable'=>false,
                            'candidate_id'=>null,'executable'=>false,'issues'=>[$error->getMessage()],
                        ];
                    }
                }
            }
        }

        $canonical=$this->canonicalList($observations);
        return [
            'mode'=>'DETERMINISTIC_MARKET_STATE_REPLAY','mutated'=>false,
            'snapshot_count'=>count($snapshots),'observation_count'=>count($canonical),
            'detected_count'=>$detected,'executable_count'=>$executable,
            'dataset_hash'=>hash('sha256',json_encode($canonical,JSON_THROW_ON_ERROR|JSON_PRESERVE_ZERO_FRACTION)),
            'observations'=>$canonical,
        ];
    }

    private function relationshipValid(
        string $organizationId,
        \Domains\CapitalMarkets\Domain\Instrument\InstrumentId $left,
        \Domains\CapitalMarkets\Domain\Instrument\InstrumentId $right,
        SpreadDetectorConfig $config,
        \DateTimeImmutable $at,
    ):bool{
        $best=Decimal::fromString('0');
        foreach($this->relationships->forInstrument($organizationId,$left) as $relationship){
            if(!$relationship instanceof EconomicRelationship||!$relationship->activeAt($at))continue;
            $matches=($relationship->sourceInstrument->equals($left)&&$relationship->targetInstrument->equals($right))
                ||($relationship->sourceInstrument->equals($right)&&$relationship->targetInstrument->equals($left));
            if(!$matches)continue;
            $metadataScore=$relationship->metadata['economic_equivalence_score']??null;
            $score=$metadataScore!==null
                ?Decimal::fromString((string)$metadataScore)
                :match($relationship->strength){
                    EconomicRelationshipStrength::Exact=>Decimal::fromString('1'),
                    EconomicRelationshipStrength::Direct=>Decimal::fromString('0.9'),
                    EconomicRelationshipStrength::Derived=>Decimal::fromString('0.7'),
                    EconomicRelationshipStrength::Statistical=>Decimal::fromString('0.3'),
                };
            if($score->compareTo($best)>0)$best=$score;
        }
        return $best->compareTo($config->economicEquivalenceThreshold)>=0;
    }

    private function snapshotConversion(
        MarketSnapshot $snapshot,
        \Domains\CapitalMarkets\Domain\MarketData\ReferenceMarketState $reference,
        \Domains\CapitalMarkets\Domain\MarketData\MarketState $token,
    ):?ConversionRate{
        if($reference->currentQuote===null||$token->bestQuote===null)return null;
        $target=$reference->currentQuote->askPrice->quoteAsset;
        $source=$token->bestQuote->askPrice->quoteAsset;
        if($source->equals($target))return null;

        foreach($snapshot->instrumentStates as $state){
            if($state->bestQuote===null||!$state->quality->status->isUsableForDecision())continue;
            $base=$state->bestQuote->bidPrice->baseAsset;
            $quote=$state->bestQuote->bidPrice->quoteAsset;
            if($base->equals($source)&&$quote->equals($target)){
                return new ConversionRate($source,$target,$state->bestQuote->midPrice(),$state->sourceTimestamp,$state->sourceId,$state->quality->status);
            }
            if($base->equals($target)&&$quote->equals($source)){
                return new ConversionRate(
                    $source,$target,
                    DecimalMath::divide(Decimal::fromString('1'),$state->bestQuote->midPrice(),18),
                    $state->sourceTimestamp,$state->sourceId,$state->quality->status
                );
            }
        }
        throw new \DomainException('REPLAY_QUOTE_CONVERSION_MISSING');
    }

    /** @param list<array<string,mixed>> $rows @return list<array<string,mixed>> */
    private function canonicalList(array $rows):array
    {
        foreach($rows as &$row){ksort($row);if(isset($row['issues'])&&is_array($row['issues']))sort($row['issues']);}
        unset($row);
        return $rows;
    }

    /** @param array<string,mixed> $options */
    private function config(array $options):SpreadDetectorConfig
    {
        return new SpreadDetectorConfig(
            trim((string)($options['config_version']??'tokenized-equity-replay-v1')),
            $this->decimal($options,'minimum_gross_edge_bps','1'),
            $this->decimal($options,'minimum_expected_net_edge_bps','1'),
            $this->decimal($options,'minimum_expected_pnl','0.01'),
            $this->int($options,'maximum_snapshot_age_ms',2000),
            $this->int($options,'maximum_snapshot_skew_ms',500),
            $this->int($options,'minimum_data_quality',80),
            $this->decimal($options,'maximum_slippage_bps','50'),
            $this->int($options,'minimum_opportunity_ttl_ms',500),
            $this->decimal($options,'economic_equivalence_threshold','0.8'),
            $this->decimal($options,'minimum_execution_probability','0'),
        );
    }

    private function slippageCost(Decimal $price,Decimal $quantity,Decimal $bps):Decimal
    {
        if($bps->isNegative())throw new InvalidArgumentException('Slippage bps cannot be negative.');
        return DecimalMath::divide(
            DecimalMath::multiply(DecimalMath::multiply($price,$quantity),$bps),
            Decimal::fromString('10000'),18
        );
    }

    /** @param array<string,mixed> $options */
    private function requiredDecimal(array $options,string $key):Decimal
    {
        if(!array_key_exists($key,$options))throw new InvalidArgumentException($key.' is required for replay.');
        return Decimal::fromString((string)$options[$key]);
    }
    /** @param array<string,mixed> $options */
    private function decimal(array $options,string $key,string $default):Decimal{return Decimal::fromString((string)($options[$key]??$default));}
    /** @param array<string,mixed> $options */
    private function int(array $options,string $key,int $default):int{return array_key_exists($key,$options)?(int)$options[$key]:$default;}
}
