<?php
declare(strict_types=1);
namespace Domains\CapitalMarkets\Infrastructure\MarketData\Streaming;
use DomainException;
use Domains\CapitalMarkets\Application\DTO\MarketDataIngestionResult;
use PDO;
use Throwable;

/** Persisted per-capability trust. Only current source worker, LIVE canonical and
 * quality evidence may advance funding/mark/index/OI. No synthetic funding accrual. */
final readonly class MysqlDerivativeStreamTrust
{
    public function __construct(private PDO $pdo,private MysqlDerivativeLifecycleRegistry $lifecycle){}

    public function record(string $org,string $source,string $shard,string $worker,
        int $token,int $epoch,MarketDataIngestionResult $result):bool
    {
        if($result->status!=='ACCEPTED'||$result->event===null||$result->quality===null)return false;
        $event=$result->event;$type=$event->eventType()->value;
        if(!in_array($type,['MARK_PRICE','INDEX_PRICE','OPEN_INTEREST','FUNDING_RATE'],true)
            ||$event->sourceId->value()!==$source||$token<1||$epoch<1||$this->pdo->inTransaction()){
            throw new DomainException('Invalid derivative observation or transaction scope.');
        }
        $instrument=$event->instrumentId->value();
        $this->lifecycle->assertTrading($org,$source,$instrument);
        if($event->mode->value!=='LIVE'||!$result->quality->trusted()
            ||$result->quality->score<80)throw new DomainException('Untrusted derivative quote.');
        $this->pdo->beginTransaction();
        try{
            MysqlMarketSourceWriterGate::lockSource($this->pdo,$org,$source);
            // Serialize lifecycle status change with stream publication.
            $this->lifecycle->assertTrading($org,$source,$instrument);
            $lease=$this->pdo->prepare(
                'SELECT 1 FROM tn_capital_market_stream_worker_leases
                 WHERE organization_id=:org AND source_id=:source AND shard_key=:shard
                  AND owner_id=:owner AND fencing_token=:token
                  AND expires_at>UTC_TIMESTAMP(6) FOR UPDATE');
            $lease->execute(['org'=>$org,'source'=>$source,'shard'=>$shard,
                'owner'=>$worker,'token'=>$token]);
            if($lease->fetchColumn()===false)throw new DomainException('Derivative writer lease superseded.');
            $proof=$this->pdo->prepare(
                "SELECT 1 FROM tn_capital_market_canonical_events e
                 JOIN tn_capital_market_quality_metrics q
                  ON q.organization_id=e.organization_id
                 AND q.canonical_event_id=e.canonical_event_id
                 JOIN tn_capital_market_subscriptions sub
                  ON sub.organization_id=e.organization_id
                 AND sub.source_id=e.source_id AND sub.instrument_id=e.instrument_id
                 AND sub.data_type=e.event_type AND sub.status='ACTIVE'
                 WHERE e.organization_id=:org AND e.source_id=:source
                  AND e.instrument_id=:instrument AND e.canonical_event_id=:event
                  AND e.event_type=:type AND e.data_mode='LIVE'
                  AND e.received_timestamp>DATE_SUB(UTC_TIMESTAMP(6),INTERVAL 120 SECOND)
                  AND q.trust_status='TRUSTED' AND q.quality_score>=80 LIMIT 1");
            $proof->execute(['org'=>$org,'source'=>$source,'instrument'=>$instrument,
                'event'=>$event->eventId,'type'=>$type]);
            if($proof->fetchColumn()===false){
                throw new DomainException('Derivative proof missing canonical event, quality or active intent.');
            }
            // Reject replay across reconnects: canonical event existence alone
            // cannot authorize a new session to relabel an old observation.
            $previous=$this->pdo->prepare(
                "SELECT last_event_id,last_provider_at,fencing_token,session_epoch
                 FROM tn_capital_market_derivative_stream_trust
                 WHERE organization_id=:org AND source_id=:source
                   AND instrument_id=:instrument AND data_type=:type FOR UPDATE");
            $previous->execute(['org'=>$org,'source'=>$source,
                'instrument'=>$instrument,'type'=>$type]);
            $last=$previous->fetch(PDO::FETCH_ASSOC);
            if(is_array($last)){
                $providerAt=$event->timestamps->sourceTimestamp
                    ->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s.u');
                if($last['last_event_id']===$event->eventId
                    ||($last['last_provider_at']!==null
                        &&strcmp($providerAt,(string)$last['last_provider_at'])<0)
                    ||((int)$last['fencing_token']===$token
                        &&(int)$last['session_epoch']>$epoch)){
                    throw new DomainException('Derivative event replay, clock rollback or session epoch regression.');
                }
            }
            $q=$this->pdo->prepare(
                "INSERT INTO tn_capital_market_derivative_stream_trust
                 (organization_id,source_id,instrument_id,data_type,shard_key,
                  fencing_token,session_epoch,status,last_event_id,last_provider_at,
                  last_received_at,quality_score,accepted_count)
                 VALUES (:org,:source,:instrument,:type,:shard,:token,:epoch,
                    'TRUSTED',:event,:provider,:received,:quality,1)
                 ON DUPLICATE KEY UPDATE
                  shard_key=VALUES(shard_key),fencing_token=VALUES(fencing_token),
                  session_epoch=VALUES(session_epoch),status='TRUSTED',
                  last_event_id=VALUES(last_event_id),
                  last_provider_at=VALUES(last_provider_at),
                  last_received_at=VALUES(last_received_at),
                  quality_score=VALUES(quality_score),
                  accepted_count=accepted_count+1,updated_at=UTC_TIMESTAMP(6)");
            $utc=new \DateTimeZone('UTC');
            $q->execute(['org'=>$org,'source'=>$source,'instrument'=>$instrument,
                'type'=>$type,'shard'=>$shard,'token'=>$token,'epoch'=>$epoch,
                'event'=>$event->eventId,
                'provider'=>$event->timestamps->sourceTimestamp->setTimezone($utc)->format('Y-m-d H:i:s.u'),
                'received'=>$event->timestamps->receivedTimestamp->setTimezone($utc)->format('Y-m-d H:i:s.u'),
                'quality'=>$result->quality->score]);
            $this->pdo->commit();
            return true;
        }catch(Throwable $e){
            if($this->pdo->inTransaction())$this->pdo->rollBack();
            throw $e;
        }
    }

    public function sweep(string $org,string $source,int $maxAge=120):int
    {
        if($org===''||$source===''||$maxAge<30||$maxAge>3600||$this->pdo->inTransaction()){
            throw new DomainException('Invalid derivative freshness sweep.');
        }
        $q=$this->pdo->prepare(
            "UPDATE tn_capital_market_derivative_stream_trust
             SET status='STALE',quality_score=0,updated_at=UTC_TIMESTAMP(6)
             WHERE organization_id=:org AND source_id=:source AND status='TRUSTED'
               AND (last_received_at IS NULL OR
                 last_received_at<DATE_SUB(UTC_TIMESTAMP(6),INTERVAL :age SECOND))");
        $q->bindValue(':org',$org);
        $q->bindValue(':source',$source);
        $q->bindValue(':age',$maxAge,PDO::PARAM_INT);$q->execute();
        return $q->rowCount();
    }

    /** @return list<array<string,mixed>> */
    public function status(string $org,string $source):array
    {
        $q=$this->pdo->prepare(
            "SELECT instrument_id,data_type,status,quality_score,accepted_count,
                    last_provider_at,last_received_at,session_epoch
             FROM tn_capital_market_derivative_stream_trust
             WHERE organization_id=:org AND source_id=:source
             ORDER BY instrument_id,data_type LIMIT 501");
        $q->execute(['org'=>$org,'source'=>$source]);return $q->fetchAll(PDO::FETCH_ASSOC);
    }
}
