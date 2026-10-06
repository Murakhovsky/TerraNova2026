<?php
declare(strict_types=1);

namespace Domains\CapitalMarkets\Application\Service;

use Domains\CapitalMarkets\Application\Contract\MarketSnapshotRepositoryInterface;
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
    ){}

    /** @param array<string,mixed> $options @return array<string,mixed> */
    public function replay(string $organizationId,array $options):array
    {
        $config=$this->config($options);
        $ttlMs=$this->int($options,'ttl_ms',1000);
        $buyFee=$this->requiredDecimal($options,'buy_fee_rate');
        $sellFee=$this->requiredDecimal($options,'sell_fee_rate');
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
                            Decimal::fromString('0'),Decimal::fromString('0'),Decimal::fromString('0'),
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
                    // H1 replay requires an explicit relationship mapping in the snapshot dataset.
                    // Without one, fail closed rather than infer equivalence from symbols.
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
