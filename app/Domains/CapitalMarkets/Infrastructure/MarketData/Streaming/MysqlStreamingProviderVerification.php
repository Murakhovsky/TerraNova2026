<?php
declare(strict_types=1);
namespace Domains\CapitalMarkets\Infrastructure\MarketData\Streaming;

use DomainException;
use Domains\CapitalMarkets\Application\DTO\MarketDataIngestionResult;
use PDO;
use Throwable;

/**
 * Durable provider-protocol subscription ACK and independently accepted
 * canonical LIVE quote receipts. No admin-proposed "ACK" evidence accepted.
 * Every mutation fenced against current source/shard worker lease in MySQL.
 */
final readonly class MysqlStreamingProviderVerification
{
    public function __construct(private PDO $pdo){}

    public function acknowledged(
        string $org,string $source,string $shard,string $worker,int $token,
        int $epoch,string $provider,string $instrument,string $channel='BBO'
    ):void{
        $this->check($org,$source,$shard,$worker,$token,$epoch,$instrument,$channel);
        if(!in_array($provider,['BINANCE_SPOT','MASSIVE_STOCKS'],true)){
            throw new DomainException('Provider acknowledgement must originate in an approved protocol.');
        }
        $this->fenced($org,$source,$shard,$worker,$token,function()use(
            $org,$source,$shard,$token,$epoch,$provider,$instrument,$channel
        ):void{
            $query=$this->pdo->prepare(
                "INSERT INTO tn_capital_market_provider_stream_receipts
                 (organization_id,source_id,instrument_id,channel,shard_key,
                  fencing_token,session_epoch,provider,status,ack_at)
                 VALUES (:org,:source,:instrument,:channel,:shard,:token,:epoch,:provider,
                         'ACKED',UTC_TIMESTAMP(6))
                 ON DUPLICATE KEY UPDATE
                  shard_key=VALUES(shard_key),fencing_token=VALUES(fencing_token),
                  session_epoch=VALUES(session_epoch),provider=VALUES(provider),
                  status='ACKED',ack_at=UTC_TIMESTAMP(6),last_live_quote_at=NULL,
                  last_quality_score=0,quote_count=0,ack_count=ack_count+1,
                  last_error_code=NULL,updated_at=UTC_TIMESTAMP(6)"
            );
            $query->execute(['org'=>$org,'source'=>$source,'instrument'=>$instrument,
                'channel'=>$channel,'shard'=>$shard,'token'=>$token,
                'epoch'=>$epoch,'provider'=>$provider]);
        });
    }

    public function acceptedQuote(
        string $org,string $source,string $shard,string $worker,int $token,int $epoch,
        MarketDataIngestionResult $result
    ):void{
        if($result->status!=='ACCEPTED'||$result->event===null||$result->quality===null){
            return;
        }
        $event=$result->event;
        $instrument=$event->instrumentId->value();
        $this->check($org,$source,$shard,$worker,$token,$epoch,$instrument,'BBO');
        if($event->sourceId->value()!==$source||$event->eventType()->value!=='BBO'){
            throw new DomainException('Provider quote source/channel mismatch.');
        }
        // DELAYED is allowed for reference research but cannot be used as LIVE.
        if($event->mode->value!=='LIVE')return;
        $live=$event->mode->value==='LIVE'&&$result->quality->trusted()
            &&$result->quality->score>=80;
        $this->fenced($org,$source,$shard,$worker,$token,function()use(
            $org,$source,$shard,$instrument,$token,$epoch,$result,$live
        ):void{
            $query=$this->pdo->prepare(
                "UPDATE tn_capital_market_provider_stream_receipts
                 SET status=CASE WHEN :live=1 THEN 'LIVE_QUOTE' ELSE 'REVOKED' END,
                     last_live_quote_at=CASE WHEN :is_live=1 THEN UTC_TIMESTAMP(6)
                           ELSE last_live_quote_at END,
                     quote_count=quote_count+1,
                     last_quality_score=:score,
                     last_error_code=CASE WHEN :good=1 THEN NULL ELSE 'QUOTE_NOT_TRUSTED' END,
                     updated_at=UTC_TIMESTAMP(6)
                 WHERE organization_id=:org AND source_id=:source
                  AND instrument_id=:instrument AND channel='BBO'
                  AND shard_key=:shard AND fencing_token=:token
                  AND session_epoch=:epoch AND status IN ('ACKED','LIVE_QUOTE')");
            $query->execute([
                'live'=>$live?1:0,'is_live'=>$live?1:0,'good'=>$live?1:0,
                'score'=>$result->quality->score,'org'=>$org,'source'=>$source,
                'instrument'=>$instrument,'shard'=>$shard,'token'=>$token,'epoch'=>$epoch,
            ]);
            if($query->rowCount()!==1){
                throw new DomainException('Provider quote lacks current session subscription ACK.');
            }
        });
    }

    public function revoke(string $org,string $source,?string $instrument,string $code):void
    {
        if(!$this->pdo->inTransaction()){
            throw new DomainException('Provider receipt revocation requires fenced transaction.');
        }
        if($code===''||strlen($code)>100||preg_match('/^[A-Z0-9_]+$/D',$code)!==1){
            throw new DomainException('Invalid provider verification revocation code.');
        }
        $scope=$instrument===null?'':' AND instrument_id=:instrument';
        $sql="UPDATE tn_capital_market_provider_stream_receipts
              SET status='REVOKED',last_error_code=:code,updated_at=UTC_TIMESTAMP(6)
              WHERE organization_id=:org AND source_id=:source".$scope;
        $stmt=$this->pdo->prepare($sql);
        $params=['org'=>$org,'source'=>$source,'code'=>$code];
        if($instrument!==null)$params['instrument']=$instrument;
        $stmt->execute($params);
    }

    /**
     * Only ledger receipts from actual provider ACK callbacks and persisted
     * canonical quote admission may count as verified. Operator cannot forge.
     */
    public function evidence(string $org,array $allowedSources):array
    {
        if($org===''||$allowedSources===[]||count($allowedSources)>64
            ||count(array_unique($allowedSources))!==count($allowedSources)){
            throw new DomainException('Explicit provider receipt scope required.');
        }
        $params=['org'=>$org];$slots=[];
        foreach($allowedSources as $i=>$source){
            if(!is_string($source)||$source===''||strlen($source)>190){
                throw new DomainException('Invalid provider source.');
            }
            $params['s'.$i]=$source;$slots[]=':s'.$i;
        }
        $sql="SELECT p.source_id,p.instrument_id,p.channel,p.provider,p.status,
                 p.fencing_token,p.session_epoch,p.ack_at,p.last_live_quote_at,
                 p.quote_count,p.last_quality_score,
                 CASE WHEN l.owner_id<>'' AND l.fencing_token=p.fencing_token
                     AND l.expires_at>UTC_TIMESTAMP(6) THEN 1 ELSE 0 END AS live_lease
              FROM tn_capital_market_provider_stream_receipts p
              LEFT JOIN tn_capital_market_stream_worker_leases l
                 ON l.organization_id=p.organization_id AND l.source_id=p.source_id
                AND l.shard_key=p.shard_key
              WHERE p.organization_id=:org AND p.source_id IN (".implode(',',$slots).")
              ORDER BY p.source_id,p.instrument_id LIMIT 3001";
        $query=$this->pdo->prepare($sql);$query->execute($params);
        $rows=$query->fetchAll(PDO::FETCH_ASSOC);
        if(count($rows)>3000)throw new DomainException('Provider receipt reporting capacity exceeded.');
        return $rows;
    }

    private function check(string $org,string $source,string $shard,string $worker,
        int $token,int $epoch,string $instrument,string $channel):void
    {
        foreach([$org,$source,$shard,$worker,$instrument] as $value){
            if($value===''||strlen($value)>190||trim($value)!==$value){
                throw new DomainException('Invalid provider verification identity.');
            }
        }
        if($token<1||$epoch<1||$channel!=='BBO'){
            throw new DomainException('Invalid provider worker epoch or channel.');
        }
    }

    private function fenced(string $org,string $source,string $shard,string $worker,
        int $token,callable $write):void
    {
        if($this->pdo->inTransaction()){
            throw new DomainException('Provider verification needs isolated fenced transaction.');
        }
        $this->pdo->beginTransaction();
        try{
            $lock=$this->pdo->prepare(
                'SELECT fencing_token FROM tn_capital_market_stream_worker_leases
                 WHERE organization_id=:org AND source_id=:source AND shard_key=:shard
                   AND owner_id=:worker AND fencing_token=:token
                   AND expires_at>UTC_TIMESTAMP(6) FOR UPDATE');
            $lock->execute(['org'=>$org,'source'=>$source,'shard'=>$shard,
                'worker'=>$worker,'token'=>$token]);
            if($lock->fetchColumn()===false){
                throw new DomainException('Provider receipt fenced by expired/foreign worker.');
            }
            $write();
            $this->pdo->commit();
        }catch(Throwable $error){
            if($this->pdo->inTransaction())$this->pdo->rollBack();
            throw $error;
        }
    }
}
