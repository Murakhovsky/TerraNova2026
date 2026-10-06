<?php
declare(strict_types=1);

namespace Domains\CapitalMarkets\Application\Service;

use Domains\CapitalMarkets\Application\Contract\MarketSnapshotRepositoryInterface;
use Domains\CapitalMarkets\Domain\MarketData\MarketSnapshot;
use InvalidArgumentException;

final readonly class TokenizedEquityHistoricalReplayService
{
    public function __construct(private MarketSnapshotRepositoryInterface $snapshots){}

    /** @return array<string,mixed> */
    public function replay(string $organizationId,string $snapshotId):array
    {
        $snapshot=$this->snapshots->get($organizationId,$snapshotId);
        if(!$snapshot instanceof MarketSnapshot)throw new InvalidArgumentException('Market snapshot not found.');

        $payload=$snapshot->toArray();
        $canonical=$this->canonical($payload);
        return [
            'snapshot_id'=>$snapshot->snapshotId,
            'snapshot_created_at'=>$snapshot->createdAt->format(DATE_ATOM),
            'instrument_state_count'=>count($snapshot->instrumentStates),
            'reference_state_count'=>count($snapshot->referenceStates),
            'source_versions'=>$snapshot->sourceVersions,
            'dataset_hash'=>hash('sha256',json_encode($canonical,JSON_THROW_ON_ERROR|JSON_PRESERVE_ZERO_FRACTION)),
            'mode'=>'DETERMINISTIC_MARKET_STATE_REPLAY',
            'mutated'=>false,
            'dataset'=>$canonical,
        ];
    }

    /** @param array<string,mixed> $value @return array<string,mixed> */
    private function canonical(array $value):array
    {
        ksort($value);
        foreach($value as $key=>$item){
            if(is_array($item)&&!array_is_list($item))$value[$key]=$this->canonical($item);
            elseif(is_array($item)){
                $value[$key]=array_map(fn(mixed $row):mixed=>is_array($row)&&!array_is_list($row)?$this->canonical($row):$row,$item);
            }
        }
        return $value;
    }
}
