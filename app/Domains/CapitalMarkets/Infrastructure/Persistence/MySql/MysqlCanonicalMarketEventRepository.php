<?php
declare(strict_types=1);

namespace Domains\CapitalMarkets\Infrastructure\Persistence\MySql;

use DateTimeImmutable;
use Domains\CapitalMarkets\Application\Contract\CanonicalMarketEventRepositoryInterface;
use Domains\CapitalMarkets\Domain\Instrument\InstrumentId;
use Domains\CapitalMarkets\Domain\MarketData\CanonicalMarketEvent;
use PDO;
use PDOException;

final readonly class MysqlCanonicalMarketEventRepository implements CanonicalMarketEventRepositoryInterface
{
    public function __construct(private PDO $connection,private MarketDataHydrator $hydrator){}

    public function append(string $organizationId,CanonicalMarketEvent $event):bool
    {
        try{
            $statement=$this->connection->prepare(
                'INSERT INTO tn_capital_market_canonical_events
                 (organization_id,canonical_event_id,source_id,venue_id,instrument_id,event_type,source_timestamp,received_timestamp,
                  processed_timestamp,sequence_value,payload_json,quality_flags_json,schema_version,fingerprint,data_mode,market_status)
                 VALUES
                 (:organization_id,:canonical_event_id,:source_id,:venue_id,:instrument_id,:event_type,:source_timestamp,:received_timestamp,
                  :processed_timestamp,:sequence_value,:payload_json,:quality_flags_json,:schema_version,:fingerprint,:data_mode,:market_status)'
            );
            $statement->execute([
                'organization_id'=>$organizationId,'canonical_event_id'=>$event->eventId,'source_id'=>$event->sourceId->value(),
                'venue_id'=>$event->venueId?->value(),'instrument_id'=>$event->instrumentId->value(),'event_type'=>$event->eventType()->value,
                'source_timestamp'=>$event->timestamps->sourceTimestamp->format('Y-m-d H:i:s.u'),
                'received_timestamp'=>$event->timestamps->receivedTimestamp->format('Y-m-d H:i:s.u'),
                'processed_timestamp'=>$event->timestamps->processedTimestamp->format('Y-m-d H:i:s.u'),
                'sequence_value'=>$event->sequence,'payload_json'=>json_encode((object)$event->observation->toArray(),JSON_THROW_ON_ERROR),
                'quality_flags_json'=>json_encode(array_map(static fn($flag):string=>$flag->value,$event->qualityFlags),JSON_THROW_ON_ERROR),
                'schema_version'=>$event->schemaVersion,'fingerprint'=>$event->fingerprint(),
                'data_mode'=>$event->mode->value,'market_status'=>$event->marketStatus->value,
            ]);
            return true;
        }catch(PDOException $error){
            if(($error->errorInfo[1]??null)===1062)return false;
            throw $error;
        }
    }

    public function history(string $organizationId,InstrumentId $instrumentId,DateTimeImmutable $from,DateTimeImmutable $to,int $limit=1000):array
    {
        $limit=max(1,min(10000,$limit));
        $statement=$this->connection->prepare(
            'SELECT * FROM tn_capital_market_canonical_events
             WHERE organization_id=:organization_id AND instrument_id=:instrument_id
               AND source_timestamp>=:from_time AND source_timestamp<=:to_time
             ORDER BY source_timestamp,id LIMIT '.$limit
        );
        $statement->execute([
            'organization_id'=>$organizationId,'instrument_id'=>$instrumentId->value(),
            'from_time'=>$from->format('Y-m-d H:i:s.u'),'to_time'=>$to->format('Y-m-d H:i:s.u'),
        ]);
        return array_map(fn(array $row):CanonicalMarketEvent=>$this->hydrator->canonicalEvent($row),$statement->fetchAll(PDO::FETCH_ASSOC));
    }
}
