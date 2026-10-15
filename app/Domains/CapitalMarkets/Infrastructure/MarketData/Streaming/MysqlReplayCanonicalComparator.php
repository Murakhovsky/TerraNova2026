<?php
declare(strict_types=1);
namespace Domains\CapitalMarkets\Infrastructure\MarketData\Streaming;

use DomainException;
use Domains\CapitalMarkets\Domain\MarketData\{CanonicalMarketEvent,MarketDataMode};
use PDO;

/**
 * Read-only drift comparison between replay-normalized event and historical
 * canonical persisted fingerprint. No mutation of LIVE trust/recovery state.
 */
final readonly class MysqlReplayCanonicalComparator
{
    public function __construct(private PDO $pdo){}

    public function compare(string $org,CanonicalMarketEvent $event):array
    {
        if($org===''||strlen($org)>190
            ||$event->mode!==MarketDataMode::Replay||$this->pdo->inTransaction()){
            throw new DomainException('Canonical comparison requires read-only REPLAY context.');
        }
        $q=$this->pdo->prepare(
            "SELECT source_id,instrument_id,event_type,fingerprint,schema_version,
                    data_mode FROM tn_capital_market_canonical_events
             WHERE organization_id=:org AND canonical_event_id=:id");
        $q->execute(['org'=>$org,'id'=>$event->eventId]);
        $stored=$q->fetch(PDO::FETCH_ASSOC);
        if(!is_array($stored)){
            return ['status'=>'NO_CANONICAL_BASELINE','raw_event_id'=>$event->eventId];
        }
        $same=$stored['source_id']===$event->sourceId->value()
            &&$stored['instrument_id']===$event->instrumentId->value()
            &&$stored['event_type']===$event->eventType()->value
            &&(int)$stored['schema_version']===$event->schemaVersion
            &&in_array($stored['data_mode'],['LIVE','DELAYED','HISTORICAL'],true)
            &&hash_equals((string)$stored['fingerprint'],$event->fingerprint());
        return ['status'=>$same?'CANONICAL_MATCH':'CANONICAL_DRIFT',
            'raw_event_id'=>$event->eventId,
            'replayed_fingerprint'=>$event->fingerprint(),
            'stored_fingerprint'=>$stored['fingerprint']];
    }
}
