<?php
declare(strict_types=1);
namespace Domains\CapitalMarkets\Infrastructure\MarketData\Streaming;

use DomainException;
use Domains\CapitalMarkets\Application\DTO\MarketDataIngestionResult;
use Domains\CapitalMarkets\Application\Service\PerInstrumentStreamTrustMachine;
use Domains\CapitalMarkets\Domain\MarketData\MarketEventType;
use PDO;

/**
 * The enclosing fenced-ingestion transaction owns the source lease row lock.
 * A sibling symbol cannot reset another symbol's sequence, freshness or trust.
 */
final readonly class MysqlPerInstrumentStreamTrust
{
    public function __construct(private PDO $pdo,private PerInstrumentStreamTrustMachine $policy){}

    /** @return string TRUSTED|UNTRUSTED|IGNORED */
    public function observe(string $org,string $source,string $shard,int $token,
        MarketDataIngestionResult $result):string
    {
        if(!$this->pdo->inTransaction()||$token<1||$shard===''){
            throw new DomainException('Per-instrument trust requires fenced ingestion transaction.');
        }
        if($result->status!=='ACCEPTED'||$result->event===null||$result->quality===null){
            return 'IGNORED';
        }
        $event=$result->event;
        $type=$event->eventType();
        $channel=match($type){
            MarketEventType::Bbo=>'BBO',
            MarketEventType::OrderBookSnapshot,MarketEventType::OrderBookDelta=>'L2',
            default=>null,
        };
        if($channel===null)return 'IGNORED';
        if($event->sourceId->value()!==$source){
            throw new DomainException('Per-instrument source mismatch.');
        }
        $instrument=$event->instrumentId->value();
        $keys=['org'=>$org,'source'=>$source,'instrument'=>$instrument,'channel'=>$channel];
        $create=$this->pdo->prepare(
            "INSERT IGNORE INTO tn_capital_market_instrument_stream_trust
             (organization_id,source_id,instrument_id,channel,shard_key,fencing_token)
             VALUES (:org,:source,:instrument,:channel,:shard,0)");
        $create->execute($keys+['shard'=>$shard]);
        $query=$this->pdo->prepare(
            'SELECT status,recovery_required,last_sequence,last_event_at,shard_key,fencing_token
             FROM tn_capital_market_instrument_stream_trust
             WHERE organization_id=:org AND source_id=:source
               AND instrument_id=:instrument AND channel=:channel FOR UPDATE');
        $query->execute($keys);
        $old=$query->fetch(PDO::FETCH_ASSOC);
        if(!is_array($old))throw new DomainException('Per-instrument trust row missing.');
        $same=(string)$old['shard_key']===$shard && (int)$old['fencing_token']===$token;
        $prior=$same?$old:null;
        $fresh=$event->mode->value==='LIVE'
            && $result->quality->trusted() && $result->quality->score>=80;
        $received=$event->timestamps->receivedTimestamp;
        if($prior!==null && $old['last_event_at']!==null){
            $last=new \DateTimeImmutable($old['last_event_at'],new \DateTimeZone('UTC'));
            if($received<$last)$fresh=false;
        }
        $decision=$this->policy->decide($prior,$channel,$type->value,
            $event->sequence,$fresh,$token);
        $trusted=$decision['status']==='TRUSTED';
        $stamp=$received->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s.u');
        $update=$this->pdo->prepare(
            'UPDATE tn_capital_market_instrument_stream_trust
             SET status=:status,reason=:reason,recovery_required=:recovery,
                 fencing_token=:token,shard_key=:shard,last_sequence=:sequence,
                 last_event_at=CASE WHEN last_event_at IS NULL OR last_event_at<:stamp
                      THEN :stamp_copy ELSE last_event_at END,
                 last_quality_score=:score,
                 accepted_count=accepted_count+:accepted,rejected_count=rejected_count+:rejected,
                 updated_at=UTC_TIMESTAMP(6)
             WHERE organization_id=:org AND source_id=:source
               AND instrument_id=:instrument AND channel=:channel');
        $update->execute($keys+[
            'status'=>$decision['status'],'reason'=>$decision['reason'],
            'recovery'=>$decision['recovery_required'],'token'=>$token,'shard'=>$shard,
            'sequence'=>$decision['sequence'],'stamp'=>$stamp,'stamp_copy'=>$stamp,
            'score'=>$result->quality->score,'accepted'=>$trusted?1:0,'rejected'=>$trusted?0:1,
        ]);
        if(!$trusted){
            // Same transaction as canonical ingest: a rejected symbol cannot
            // retain a TRUSTED market state. Never touch sibling instruments.
            foreach(['tn_capital_market_states','tn_capital_market_reference_states'] as $table){
                $sql="UPDATE $table SET trust_status='UNTRUSTED',quality_score=0,
                    state_json=JSON_SET(state_json,'$.trust_status','UNTRUSTED',
                        '$.quality_status','UNTRUSTED','$.quality.status','UNTRUSTED',
                        '$.quality.score',0),
                    updated_at=UTC_TIMESTAMP(6)
                    WHERE organization_id=:org AND source_id=:source
                      AND instrument_id=:instrument AND trust_status<>'UNTRUSTED'";
                $stmt=$this->pdo->prepare($sql);
                $stmt->execute(['org'=>$org,'source'=>$source,'instrument'=>$instrument]);
            }
        }
        return $decision['status'];
    }
}
