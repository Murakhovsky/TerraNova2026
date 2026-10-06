<?php
declare(strict_types=1);

namespace Domains\CapitalMarkets\Infrastructure\Persistence\MySql;

use Domains\CapitalMarkets\Application\Contract\MarketStateRepositoryInterface;
use Domains\CapitalMarkets\Domain\Instrument\InstrumentId;
use Domains\CapitalMarkets\Domain\MarketData\MarketSourceId;
use Domains\CapitalMarkets\Domain\MarketData\MarketState;
use Domains\CapitalMarkets\Domain\MarketData\ReferenceMarketState;
use Domains\CapitalMarkets\Domain\Venue\VenueId;
use PDO;

final readonly class MysqlMarketStateRepository implements MarketStateRepositoryInterface
{
    public function __construct(private PDO $connection,private MarketDataHydrator $hydrator){}

    public function save(string $organizationId,MarketState $state):void
    {
        $statement=$this->connection->prepare(
            'INSERT INTO tn_capital_market_states
             (organization_id,venue_id,instrument_id,source_id,state_json,trust_status,quality_score,state_version,
              source_timestamp,updated_at,last_sequence,last_event_fingerprint,data_mode)
             VALUES
             (:organization_id,:venue_id,:instrument_id,:source_id,:state_json,:trust_status,:quality_score,:state_version,
              :source_timestamp,:updated_at,:last_sequence,:last_event_fingerprint,:data_mode)
             ON DUPLICATE KEY UPDATE
              source_id=VALUES(source_id),state_json=VALUES(state_json),trust_status=VALUES(trust_status),
              quality_score=VALUES(quality_score),state_version=VALUES(state_version),source_timestamp=VALUES(source_timestamp),
              updated_at=VALUES(updated_at),last_sequence=VALUES(last_sequence),
              last_event_fingerprint=VALUES(last_event_fingerprint),data_mode=VALUES(data_mode)'
        );
        $statement->execute([
            'organization_id'=>$organizationId,'venue_id'=>$state->venueId->value(),'instrument_id'=>$state->instrumentId->value(),
            'source_id'=>$state->sourceId->value(),'state_json'=>json_encode($state->toArray(),JSON_THROW_ON_ERROR),
            'trust_status'=>$state->quality->status->value,'quality_score'=>$state->quality->score,'state_version'=>$state->stateVersion,
            'source_timestamp'=>$state->sourceTimestamp->format('Y-m-d H:i:s.u'),'updated_at'=>$state->updatedAt->format('Y-m-d H:i:s.u'),
            'last_sequence'=>$state->lastSequence,'last_event_fingerprint'=>$state->lastEventFingerprint,'data_mode'=>$state->mode->value,
        ]);
    }

    public function get(string $organizationId,VenueId $venueId,InstrumentId $instrumentId):?MarketState
    {
        $statement=$this->connection->prepare(
            'SELECT state_json FROM tn_capital_market_states
             WHERE organization_id=:organization_id AND venue_id=:venue_id AND instrument_id=:instrument_id LIMIT 1'
        );
        $statement->execute(['organization_id'=>$organizationId,'venue_id'=>$venueId->value(),'instrument_id'=>$instrumentId->value()]);
        $json=$statement->fetchColumn();
        return is_string($json)?$this->hydrator->marketState($this->object($json)):null;
    }

    public function list(string $organizationId,int $limit=200):array
    {
        $limit=max(1,min(1000,$limit));
        $statement=$this->connection->prepare(
            'SELECT state_json FROM tn_capital_market_states WHERE organization_id=:organization_id ORDER BY updated_at DESC LIMIT '.$limit
        );
        $statement->execute(['organization_id'=>$organizationId]);
        $out=[];
        foreach($statement->fetchAll(PDO::FETCH_ASSOC) as $row)$out[]=$this->hydrator->marketState($this->object((string)$row['state_json']));
        return $out;
    }

    public function saveReference(string $organizationId,ReferenceMarketState $state):void
    {
        $statement=$this->connection->prepare(
            'INSERT INTO tn_capital_market_reference_states
             (organization_id,source_id,instrument_id,state_json,trust_status,quality_score,state_version,updated_at,data_mode)
             VALUES
             (:organization_id,:source_id,:instrument_id,:state_json,:trust_status,:quality_score,:state_version,:updated_at,:data_mode)
             ON DUPLICATE KEY UPDATE
              state_json=VALUES(state_json),trust_status=VALUES(trust_status),quality_score=VALUES(quality_score),
              state_version=VALUES(state_version),updated_at=VALUES(updated_at),data_mode=VALUES(data_mode)'
        );
        $statement->execute([
            'organization_id'=>$organizationId,'source_id'=>$state->sourceId->value(),'instrument_id'=>$state->instrumentId->value(),
            'state_json'=>json_encode($state->toArray(),JSON_THROW_ON_ERROR),'trust_status'=>$state->quality->status->value,
            'quality_score'=>$state->quality->score,'state_version'=>$state->stateVersion,
            'updated_at'=>$state->updatedAt->format('Y-m-d H:i:s.u'),'data_mode'=>$state->mode->value,
        ]);
    }

    public function getReference(string $organizationId,MarketSourceId $sourceId,InstrumentId $instrumentId):?ReferenceMarketState
    {
        $statement=$this->connection->prepare(
            'SELECT state_json FROM tn_capital_market_reference_states
             WHERE organization_id=:organization_id AND source_id=:source_id AND instrument_id=:instrument_id LIMIT 1'
        );
        $statement->execute(['organization_id'=>$organizationId,'source_id'=>$sourceId->value(),'instrument_id'=>$instrumentId->value()]);
        $json=$statement->fetchColumn();
        return is_string($json)?$this->hydrator->referenceState($this->object($json)):null;
    }

    public function listReferences(string $organizationId,int $limit=200):array
    {
        $limit=max(1,min(1000,$limit));
        $statement=$this->connection->prepare(
            'SELECT state_json FROM tn_capital_market_reference_states
             WHERE organization_id=:organization_id ORDER BY updated_at DESC LIMIT '.$limit
        );
        $statement->execute(['organization_id'=>$organizationId]);
        $out=[];
        foreach($statement->fetchAll(PDO::FETCH_ASSOC) as $row)$out[]=$this->hydrator->referenceState($this->object((string)$row['state_json']));
        return $out;
    }

    /** @return array<string,mixed> */
    private function object(string $json):array
    {
        $value=json_decode($json,true,flags:JSON_THROW_ON_ERROR);
        return is_array($value)&&!array_is_list($value)?$value:[];
    }
}
