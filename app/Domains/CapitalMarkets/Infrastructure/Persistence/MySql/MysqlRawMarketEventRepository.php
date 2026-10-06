<?php
declare(strict_types=1);

namespace Domains\CapitalMarkets\Infrastructure\Persistence\MySql;

use DateTimeImmutable;
use Domains\CapitalMarkets\Application\Contract\RawMarketEventRepositoryInterface;
use Domains\CapitalMarkets\Domain\MarketData\MarketDataMode;
use Domains\CapitalMarkets\Domain\MarketData\MarketSourceId;
use Domains\CapitalMarkets\Domain\MarketData\RawMarketEvent;
use Domains\CapitalMarkets\Domain\Venue\VenueId;
use PDO;
use PDOException;

final readonly class MysqlRawMarketEventRepository implements RawMarketEventRepositoryInterface
{
    public function __construct(private PDO $connection){}

    public function append(string $organizationId,RawMarketEvent $event):bool
    {
        try{
            $statement=$this->connection->prepare(
                'INSERT INTO tn_capital_market_raw_events
                 (organization_id,raw_event_id,source_id,venue_id,external_instrument,event_type,provider_timestamp,
                  received_at,sequence_value,raw_payload_json,transport_metadata_json,data_mode)
                 VALUES
                 (:organization_id,:raw_event_id,:source_id,:venue_id,:external_instrument,:event_type,:provider_timestamp,
                  :received_at,:sequence_value,:raw_payload_json,:transport_metadata_json,:data_mode)'
            );
            $statement->execute([
                'organization_id'=>$organizationId,'raw_event_id'=>$event->eventId,'source_id'=>$event->sourceId->value(),
                'venue_id'=>$event->venueId?->value(),'external_instrument'=>$event->externalInstrument,'event_type'=>$event->eventType,
                'provider_timestamp'=>$event->providerTimestamp?->format('Y-m-d H:i:s.u'),
                'received_at'=>$event->receivedAt->format('Y-m-d H:i:s.u'),'sequence_value'=>$event->sequence,
                'raw_payload_json'=>json_encode((object)$event->rawPayload,JSON_THROW_ON_ERROR),
                'transport_metadata_json'=>json_encode((object)$event->transportMetadata,JSON_THROW_ON_ERROR),
                'data_mode'=>$event->mode->value,
            ]);
            return true;
        }catch(PDOException $error){
            if(($error->errorInfo[1]??null)===1062)return false;
            throw $error;
        }
    }

    public function range(string $organizationId,MarketSourceId $sourceId,DateTimeImmutable $from,DateTimeImmutable $to,int $limit=1000):array
    {
        $limit=max(1,min(10000,$limit));
        $statement=$this->connection->prepare(
            'SELECT * FROM tn_capital_market_raw_events
             WHERE organization_id=:organization_id AND source_id=:source_id
               AND received_at>=:from_time AND received_at<=:to_time
             ORDER BY received_at,id LIMIT '.$limit
        );
        $statement->execute([
            'organization_id'=>$organizationId,'source_id'=>$sourceId->value(),
            'from_time'=>$from->format('Y-m-d H:i:s.u'),'to_time'=>$to->format('Y-m-d H:i:s.u'),
        ]);
        return array_map(static fn(array $row):RawMarketEvent=>new RawMarketEvent(
            (string)$row['raw_event_id'],MarketSourceId::fromString((string)$row['source_id']),
            $row['venue_id']===null?null:VenueId::fromString((string)$row['venue_id']),
            (string)$row['external_instrument'],(string)$row['event_type'],
            $row['provider_timestamp']===null?null:new DateTimeImmutable((string)$row['provider_timestamp']),
            new DateTimeImmutable((string)$row['received_at']),
            $row['sequence_value']===null?null:(string)$row['sequence_value'],
            self::object((string)$row['raw_payload_json']),self::object((string)$row['transport_metadata_json']),
            MarketDataMode::from((string)$row['data_mode']),
        ),$statement->fetchAll(PDO::FETCH_ASSOC));
    }

    /** @return array<string,mixed> */
    private static function object(string $json):array
    {
        $value=json_decode($json,true,flags:JSON_THROW_ON_ERROR);
        return is_array($value)&&!array_is_list($value)?$value:[];
    }
}
