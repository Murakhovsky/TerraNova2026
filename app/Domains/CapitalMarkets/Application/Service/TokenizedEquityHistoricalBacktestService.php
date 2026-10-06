<?php
declare(strict_types=1);

namespace Domains\CapitalMarkets\Application\Service;

use DateTimeImmutable;
use DomainException;
use Domains\CapitalMarkets\Application\Contract\MarketSnapshotRepositoryInterface;
use Domains\CapitalMarkets\Application\Contract\TokenizedEquityBacktestRepositoryInterface;
use Domains\CapitalMarkets\Domain\Contract\RelationshipRepository;
use Domains\CapitalMarkets\Domain\Instrument\EconomicRelationship;
use Domains\CapitalMarkets\Domain\Instrument\EconomicRelationshipStrength;
use Domains\CapitalMarkets\Domain\Instrument\InstrumentId;
use Domains\CapitalMarkets\Domain\MarketData\ConversionRate;
use Domains\CapitalMarkets\Domain\MarketData\MarketSnapshot;
use Domains\CapitalMarkets\Domain\MarketData\MarketSourceId;
use Domains\CapitalMarkets\Domain\MarketData\MarketState;
use Domains\CapitalMarkets\Domain\MarketData\ReferenceMarketState;
use Domains\CapitalMarkets\Domain\Opportunity\HypothesisCode;
use Domains\CapitalMarkets\Domain\Opportunity\SpreadCandidate;
use Domains\CapitalMarkets\Domain\Opportunity\SpreadDetectorConfig;
use Domains\CapitalMarkets\Domain\Research\HypothesisResearchEngine;
use Domains\CapitalMarkets\Domain\Service\NetEconomicsEngine;
use Domains\CapitalMarkets\Domain\Service\TokenizedEquitySpreadDetector;
use Domains\CapitalMarkets\Domain\Value\AssetCode;
use Domains\CapitalMarkets\Domain\Value\Decimal;
use Domains\CapitalMarkets\Domain\Value\DecimalMath;
use InvalidArgumentException;

final readonly class TokenizedEquityHistoricalBacktestService
{
    public function __construct(
        private MarketSnapshotRepositoryInterface $snapshots,
        private TokenizedEquityBacktestRepositoryInterface $runs,
        private RelationshipRepository $relationships,
        private TokenizedEquitySpreadDetector $detector,
        private NetEconomicsEngine $economics,
        private HypothesisResearchEngine $research,
    ){}

    /**
     * @param array<string,mixed> $mapping
     * @param array<string,mixed> $options
     * @return array<string,mixed>
     */
    public function run(
        string $organizationId,
        string $hypothesis,
        string $marketPairId,
        DateTimeImmutable $from,
        DateTimeImmutable $to,
        array $mapping,
        array $options,
    ):array{
        $code=strtoupper(trim($hypothesis));
        if(!in_array($code,['H1','H2'],true))throw new InvalidArgumentException('hypothesis must be H1 or H2.');
        if($to<$from)throw new InvalidArgumentException('Backtest range end must be on or after start.');

        $buyFee=$this->requiredDecimal($options,'buy_fee_rate');
        $sellFee=$this->requiredDecimal($options,'sell_fee_rate');
        $trainBps=$this->integer($options,'train_bps',7000,1000,9000);
        $limit=$this->integer($options,'snapshot_limit',10000,2,10000);
        $minimumSample=$this->integer($options,'minimum_detected_sample',30,1,10000);
        $config=$this->config($options);

        $snapshots=$this->snapshots->listRange($organizationId,$from,$to,$limit);
        if(count($snapshots)<2)throw new DomainException('BACKTEST_REQUIRES_AT_LEAST_TWO_SNAPSHOTS');

        $split=max(1,min(count($snapshots)-1,intdiv(count($snapshots)*$trainBps,10000)));
        $train=array_slice($snapshots,0,$split);
        $oos=array_slice($snapshots,$split);

        $trainObs=$this->observations($organizationId,$code,$marketPairId,$train,$mapping,$config,$buyFee,$sellFee,$options);
        $oosObs=$this->observations($organizationId,$code,$marketPairId,$oos,$mapping,$config,$buyFee,$sellFee,$options);

        $trainSummary=$this->research->summarize($trainObs,$minimumSample,1);
        $oosSummary=$this->research->summarize($oosObs,$minimumSample,1);
        $promotion=$this->promotion($trainSummary,$oosSummary,$minimumSample);

        $datasetEvidence=array_map(static fn(MarketSnapshot $snapshot):array=>[
            'snapshot_id'=>$snapshot->snapshotId,
            'created_at'=>$snapshot->createdAt->format(DATE_ATOM),
            'source_versions'=>$snapshot->sourceVersions,
        ],$snapshots);

        $canonicalConfig=[
            'hypothesis'=>$code,
            'market_pair_id'=>$marketPairId,
            'mapping'=>$mapping,
            'options'=>$this->canonicalOptions($options),
            'train_bps'=>$trainBps,
        ];
        $datasetHash=hash('sha256',json_encode([
            'dataset'=>$datasetEvidence,
            'config'=>$canonicalConfig,
        ],JSON_THROW_ON_ERROR|JSON_PRESERVE_ZERO_FRACTION));

        $createdAt=new DateTimeImmutable();
        $runId='cm_backtest_'.substr(hash('sha256',implode('|',[
            $organizationId,$code,$marketPairId,$from->format(DATE_ATOM),$to->format(DATE_ATOM),$datasetHash,
        ])),0,40);

        $summary=[
            'promotion'=>$promotion,
            'train'=>$trainSummary,
            'out_of_sample'=>$oosSummary,
            'split'=>[
                'policy'=>'CHRONOLOGICAL',
                'train_bps'=>$trainBps,
                'train_last_snapshot'=>$train[array_key_last($train)]->snapshotId,
                'oos_first_snapshot'=>$oos[0]->snapshotId,
            ],
        ];
        $payload=[
            'run_id'=>$runId,
            'hypothesis'=>$code,
            'status'=>'COMPLETED',
            'dataset_hash'=>$datasetHash,
            'from'=>$from->format(DATE_ATOM),
            'to'=>$to->format(DATE_ATOM),
            'snapshot_count'=>count($snapshots),
            'train_count'=>count($train),
            'oos_count'=>count($oos),
            'config'=>$canonicalConfig,
            'summary'=>$summary,
            'created_at'=>$createdAt->format(DATE_ATOM),
        ];
        $this->runs->saveRun($organizationId,$runId,$code,'COMPLETED',$datasetHash,$payload);
        return $payload;
    }

    /** @return list<array<string,mixed>> */
    public function runs(string $organizationId,int $limit=50):array
    {
        return $this->runs->listRuns($organizationId,$limit);
    }

    /**
     * @param list<MarketSnapshot> $snapshots
     * @param array<string,mixed> $mapping
     * @param array<string,mixed> $options
     * @return list<array<string,mixed>>
     */
    private function observations(
        string $organizationId,
        string $hypothesis,
        string $marketPairId,
        array $snapshots,
        array $mapping,
        SpreadDetectorConfig $config,
        Decimal $buyFee,
        Decimal $sellFee,
        array $options,
    ):array{
        $out=[];
        foreach($snapshots as $snapshot){
            $detected=[];
            try{
                if($hypothesis==='H2'){
                    $a=$this->marketState($snapshot,$this->required($mapping,'venue_a'),$this->required($mapping,'instrument_a'));
                    $b=$this->marketState($snapshot,$this->required($mapping,'venue_b'),$this->required($mapping,'instrument_b'));
                    $this->assertEconomicEquivalence($organizationId,$a->instrumentId,$b->instrumentId,$config,$snapshot->createdAt);
                    $detected=$this->detector->detectCrossVenue(
                        $marketPairId,$a,$b,$config,$snapshot->createdAt,$this->integer($options,'ttl_ms',1000,1,600000)
                    );
                }else{
                    $reference=$this->referenceState(
                        $snapshot,$this->required($mapping,'reference_source'),$this->required($mapping,'underlying_instrument')
                    );
                    $token=$this->marketState(
                        $snapshot,$this->required($mapping,'token_venue'),$this->required($mapping,'token_instrument')
                    );
                    $this->assertEconomicEquivalence($organizationId,$reference->instrumentId,$token->instrumentId,$config,$snapshot->createdAt);
                    $conversion=$this->snapshotConversion($snapshot,$reference,$token);
                    $detected=$this->detector->detectReferenceDislocation(
                        $marketPairId,$reference,$token,$config,$snapshot->createdAt,$this->integer($options,'ttl_ms',1000,1,600000),$conversion
                    );
                }
                $out[]=[
                    'stage'=>'SCAN','detected'=>$detected!==[],'executable'=>false,'realized'=>false,
                    'expected_pnl'=>'0','realized_pnl'=>'0','snapshot_id'=>$snapshot->snapshotId,
                ];
                foreach($detected as $candidate){
                    $out[]=$this->evaluation($candidate,$config,$buyFee,$sellFee,$options,$snapshot->snapshotId);
                }
            }catch(DomainException){
                $out[]=[
                    'stage'=>'SCAN','detected'=>false,'executable'=>false,'realized'=>false,
                    'expected_pnl'=>'0','realized_pnl'=>'0','snapshot_id'=>$snapshot->snapshotId,
                ];
            }
        }
        return $out;
    }

    /** @param array<string,mixed> $options @return array<string,mixed> */
    private function evaluation(
        SpreadCandidate $candidate,
        SpreadDetectorConfig $config,
        Decimal $buyFee,
        Decimal $sellFee,
        array $options,
        string $snapshotId,
    ):array{
        $quantity=array_key_exists('quantity',$options)
            ?Decimal::fromString((string)$options['quantity'])
            :$candidate->quantity;
        if($quantity->compareTo($candidate->quantity)>0)$quantity=$candidate->quantity;

        $buyNotional=DecimalMath::multiply($candidate->buyPrice,$quantity);
        $sellNotional=DecimalMath::multiply($candidate->sellPrice,$quantity);
        $slippageBps=$this->decimal($options,'slippage_bps',Decimal::fromString('0'));
        if($slippageBps->compareTo($config->maximumSlippageBps)>0)$slippageBps=$config->maximumSlippageBps;
        $denom=Decimal::fromString('10000');
        $buySlip=DecimalMath::divide(DecimalMath::multiply($buyNotional,$slippageBps),$denom,12);
        $sellSlip=DecimalMath::divide(DecimalMath::multiply($sellNotional,$slippageBps),$denom,12);

        $estimate=$this->economics->estimate(
            $candidate->buyPrice,$candidate->sellPrice,$quantity,$buyFee,$sellFee,$buySlip,$sellSlip,
            $this->decimal($options,'fx_cost',Decimal::fromString('0')),
            $this->decimal($options,'hedge_cost',Decimal::fromString('0')),
            $this->decimal($options,'financing_cost',Decimal::fromString('0')),
            $this->decimal($options,'network_cost',Decimal::fromString('0')),
            $this->decimal($options,'settlement_cost',Decimal::fromString('0')),
            $this->decimal($options,'execution_risk_allowance',Decimal::fromString('0')),
        );
        $executable=$estimate->expectedNetPnl->compareTo($config->minimumExpectedPnl)>=0
            &&$estimate->expectedNetEdgeBps->compareTo($config->minimumExpectedNetEdgeBps)>=0;

        return [
            'stage'=>'EVALUATION',
            'detected'=>true,
            'executable'=>$executable,
            'realized'=>false,
            'expected_pnl'=>$estimate->expectedNetPnl->value(),
            'realized_pnl'=>'0',
            'expected_net_edge_bps'=>$estimate->expectedNetEdgeBps->value(),
            'snapshot_id'=>$snapshotId,
            'candidate_id'=>$candidate->id,
        ];
    }

    /** @param array<string,mixed> $train @param array<string,mixed> $oos */
    private function promotion(array $train,array $oos,int $minimumSample):string
    {
        $trainDetected=(int)($train['sample']['detected_count']??0);
        $oosDetected=(int)($oos['sample']['detected_count']??0);
        if($trainDetected<$minimumSample||$oosDetected<$minimumSample)return 'INSUFFICIENT_OOS_SAMPLE';

        $trainPnl=Decimal::fromString((string)($train['economics']['average_expected_pnl']??'0'));
        $oosPnl=Decimal::fromString((string)($oos['economics']['average_expected_pnl']??'0'));
        $oosExecutable=Decimal::fromString((string)($oos['economics']['executable_ratio']??'0'));
        if(!$trainPnl->isPositive())return 'TRAIN_FAIL';
        if(!$oosPnl->isPositive())return 'OOS_FAIL';
        if($oosExecutable->compareTo(Decimal::fromString('0.1'))<0)return 'OOS_NOT_EXECUTABLE';
        return 'OOS_PASS';
    }

    private function marketState(MarketSnapshot $snapshot,string $venue,string $instrument):MarketState
    {
        foreach($snapshot->instrumentStates as $state){
            if($state->venueId->value()===$venue&&$state->instrumentId->value()===$instrument)return $state;
        }
        throw new DomainException('BACKTEST_MARKET_STATE_MISSING');
    }

    private function referenceState(MarketSnapshot $snapshot,string $source,string $instrument):ReferenceMarketState
    {
        foreach($snapshot->referenceStates as $state){
            if($state->sourceId->value()===$source&&$state->instrumentId->value()===$instrument)return $state;
        }
        throw new DomainException('BACKTEST_REFERENCE_STATE_MISSING');
    }

    private function snapshotConversion(
        MarketSnapshot $snapshot,
        ReferenceMarketState $reference,
        MarketState $token,
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
        throw new DomainException('BACKTEST_QUOTE_CONVERSION_MISSING');
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
            static fn(EconomicRelationship $r):bool=>$r->activeAt($at)
                &&(($r->sourceInstrument->equals($left)&&$r->targetInstrument->equals($right))
                    ||($r->sourceInstrument->equals($right)&&$r->targetInstrument->equals($left)))
        );
        if($matches===[])throw new DomainException('ECONOMIC_RELATIONSHIP_REQUIRED');

        $best=Decimal::fromString('0');
        foreach($matches as $relationship){
            $metadataScore=$relationship->metadata['economic_equivalence_score']??null;
            $score=$metadataScore!==null?Decimal::fromString((string)$metadataScore):match($relationship->strength){
                EconomicRelationshipStrength::Exact=>Decimal::fromString('1'),
                EconomicRelationshipStrength::Direct=>Decimal::fromString('0.9'),
                EconomicRelationshipStrength::Derived=>Decimal::fromString('0.7'),
                EconomicRelationshipStrength::Statistical=>Decimal::fromString('0.3'),
            };
            if($score->compareTo($best)>0)$best=$score;
        }
        if($best->compareTo($config->economicEquivalenceThreshold)<0)throw new DomainException('ECONOMIC_EQUIVALENCE_BELOW_THRESHOLD');
    }

    /** @param array<string,mixed> $options */
    private function config(array $options):SpreadDetectorConfig
    {
        return new SpreadDetectorConfig(
            trim((string)($options['config_version']??'tokenized-equity-backtest-v1')),
            $this->decimal($options,'minimum_gross_edge_bps',Decimal::fromString('1')),
            $this->decimal($options,'minimum_expected_net_edge_bps',Decimal::fromString('1')),
            $this->decimal($options,'minimum_expected_pnl',Decimal::fromString('0.01')),
            $this->integer($options,'maximum_snapshot_age_ms',2000,1,86400000),
            $this->integer($options,'maximum_snapshot_skew_ms',500,0,86400000),
            $this->integer($options,'minimum_data_quality',80,0,100),
            $this->decimal($options,'maximum_slippage_bps',Decimal::fromString('50')),
            $this->integer($options,'minimum_opportunity_ttl_ms',500,1,600000),
            $this->decimal($options,'economic_equivalence_threshold',Decimal::fromString('0.8')),
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
    private function integer(array $options,string $key,int $default,int $min,int $max):int
    {
        $value=array_key_exists($key,$options)?(int)$options[$key]:$default;
        if($value<$min||$value>$max)throw new InvalidArgumentException($key.' is outside the allowed range.');
        return $value;
    }

    /** @param array<string,mixed> $input */
    private function required(array $input,string $key):string
    {
        $value=$input[$key]??null;
        if(!is_string($value)||trim($value)==='')throw new InvalidArgumentException($key.' is required.');
        return trim($value);
    }

    /** @param array<string,mixed> $options @return array<string,mixed> */
    private function canonicalOptions(array $options):array
    {
        ksort($options);
        return $options;
    }
}
