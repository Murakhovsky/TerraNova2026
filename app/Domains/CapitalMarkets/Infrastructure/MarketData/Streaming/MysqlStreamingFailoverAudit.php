<?php
declare(strict_types=1);
namespace Domains\CapitalMarkets\Infrastructure\MarketData\Streaming;

use DomainException;
use PDO;
use Throwable;

/**
 * Controlled source-specific lease-fencing fault injection and verified
 * recovery; no direct venue order, port or arbitrary shell operation.
 * A recovered state requires new lease token, new provider ACK + new LIVE
 * canonical quote following the challenge.
 */
final readonly class MysqlStreamingFailoverAudit
{
    public function __construct(private PDO $pdo){}

    /** @return array<string,mixed> */
    public function inject(string $org,string $source):array
    {
        $this->validate($org,$source);
        if($this->pdo->inTransaction())throw new DomainException('Failover drill cannot nest transaction.');
        $this->pdo->beginTransaction();
        try{
            MysqlMarketSourceWriterGate::lockSource($this->pdo,$org,$source);
            $phase=$this->pdo->prepare(
                "SELECT phase,source_ids_json FROM tn_capital_market_pool_production_control
                 WHERE organization_id=:org FOR UPDATE");
            $phase->execute(['org'=>$org]);$control=$phase->fetch(PDO::FETCH_ASSOC);
            $scope=is_array($control)?json_decode((string)$control['source_ids_json'],true):null;
            if(!is_array($control)||$control['phase']!=='SOAK'
                ||!is_array($scope)||!in_array($source,$scope,true)){
                throw new DomainException('Failover injection only allowed on selected active SOAK source.');
            }
            $q=$this->pdo->prepare(
                "SELECT shard_key,fencing_token,owner_id FROM tn_capital_market_stream_worker_leases
                 WHERE organization_id=:org AND source_id=:source
                   AND shard_key<>:guard AND owner_id<>''
                   AND expires_at>UTC_TIMESTAMP(6) FOR UPDATE");
            $q->execute(['org'=>$org,'source'=>$source,
                'guard'=>MysqlMarketSourceWriterGate::SOURCE_GUARD_SHARD]);
            $leases=$q->fetchAll(PDO::FETCH_ASSOC);
            if(count($leases)!==1){
                throw new DomainException('Failover requires exactly one healthy fenced owner.');
            }
            $prior=(int)$leases[0]['fencing_token'];
            $v=$this->pdo->prepare(
                "SELECT COUNT(*) FROM tn_capital_market_provider_stream_receipts
                 WHERE organization_id=:org AND source_id=:source
                 AND status='LIVE_QUOTE' AND fencing_token=:token
                 AND last_live_quote_at>DATE_SUB(UTC_TIMESTAMP(6),INTERVAL 120 SECOND)");
            $v->execute(['org'=>$org,'source'=>$source,'token'=>$prior]);
            if((int)$v->fetchColumn()<1){
                throw new DomainException('No verified live pre-failover quote.');
            }
            $existing=$this->pdo->prepare(
                "SELECT observation FROM tn_capital_market_stream_failover_observations
                 WHERE organization_id=:org AND source_id=:source
                 ORDER BY id DESC LIMIT 1 FOR UPDATE");
            $existing->execute(['org'=>$org,'source'=>$source]);
            if($existing->fetchColumn()==='INJECTED'){
                throw new DomainException('Previous failover challenge not yet recovered.');
            }
            $stmt=$this->pdo->prepare(
                "UPDATE tn_capital_market_stream_worker_leases
                 SET owner_id='',fencing_token=fencing_token+1,
                     expires_at=UTC_TIMESTAMP(6),updated_at=UTC_TIMESTAMP(6)
                 WHERE organization_id=:org AND source_id=:source
                   AND shard_key=:shard AND fencing_token=:token");
            $stmt->execute(['org'=>$org,'source'=>$source,
                'shard'=>$leases[0]['shard_key'],'token'=>$prior]);
            if($stmt->rowCount()!==1)throw new DomainException('Failover fencing race.');
            foreach(['tn_capital_market_provider_stream_receipts',
                'tn_capital_market_instrument_stream_trust'] as $table){
                if($table==='tn_capital_market_instrument_stream_trust'){
                    $stmt=$this->pdo->prepare(
                        "UPDATE $table SET status='UNTRUSTED',recovery_required=1,
                         reason='FAILOVER_INJECTED',last_quality_score=0
                         WHERE organization_id=:org AND source_id=:source");
                }else{
                    $stmt=$this->pdo->prepare(
                        "UPDATE $table SET status='REVOKED',
                         last_error_code='FAILOVER_INJECTED',last_quality_score=0
                         WHERE organization_id=:org AND source_id=:source");
                }
                $stmt->execute(['org'=>$org,'source'=>$source]);
            }
            foreach(['tn_capital_market_states','tn_capital_market_reference_states'] as $table){
                $stmt=$this->pdo->prepare(
                    "UPDATE $table SET trust_status='UNTRUSTED',quality_score=0,
                     state_json=JSON_SET(state_json,'$.trust_status','UNTRUSTED',
                        '$.quality_status','UNTRUSTED','$.quality.status','UNTRUSTED',
                        '$.quality.score',0),
                     updated_at=UTC_TIMESTAMP(6)
                     WHERE organization_id=:org AND source_id=:source");
                $stmt->execute(['org'=>$org,'source'=>$source]);
            }
            $h=$this->pdo->prepare(
                "UPDATE tn_capital_market_source_health
                 SET connection_state='DEGRADED',last_error='SUPERVISED_FAILOVER_INJECTED',
                     updated_at=UTC_TIMESTAMP(6)
                 WHERE organization_id=:org AND source_id=:source");
            $h->execute(['org'=>$org,'source'=>$source]);
            $this->record($org,$source,'INJECTED',$prior,null,false);
            $this->pdo->commit();
            return ['status'=>'INJECTED','source'=>$source,'previous_fencing_token'=>$prior,
                'expected'=>'SUPERVISED_WORKER_MUST_REACQUIRE_NEW_TOKEN_AND_PROVIDER_ACK',
                'trading_activated'=>false];
        }catch(Throwable $e){
            if($this->pdo->inTransaction())$this->pdo->rollBack();
            throw $e;
        }
    }

    /** @return array<string,mixed> */
    public function verify(string $org,string $source):array
    {
        $this->validate($org,$source);
        if($this->pdo->inTransaction())throw new DomainException('Failover verification cannot nest transaction.');
        $this->pdo->beginTransaction();
        try{
            $prev=$this->pdo->prepare(
                "SELECT id,previous_token,observed_at FROM tn_capital_market_stream_failover_observations
                 WHERE organization_id=:org AND source_id=:source AND observation='INJECTED'
                 ORDER BY id DESC LIMIT 1 FOR UPDATE");
            $prev->execute(['org'=>$org,'source'=>$source]);
            $row=$prev->fetch(PDO::FETCH_ASSOC);
            if(!is_array($row)||strtotime($row['observed_at'].' UTC')<time()-900){
                throw new DomainException('Recent supervised failover challenge missing.');
            }
            $duplicated=$this->pdo->prepare(
                "SELECT 1 FROM tn_capital_market_stream_failover_observations
                 WHERE organization_id=:org AND source_id=:source
                   AND observation='RECOVERED' AND id>:challenge LIMIT 1");
            $duplicated->execute(['org'=>$org,'source'=>$source,'challenge'=>$row['id']]);
            if($duplicated->fetchColumn()!==false){
                throw new DomainException('Failover challenge already consumed.');
            }
            $q=$this->pdo->prepare(
                "SELECT l.fencing_token FROM tn_capital_market_stream_worker_leases l
                 WHERE l.organization_id=:org AND l.source_id=:source
                   AND l.owner_id<>'' AND l.expires_at>UTC_TIMESTAMP(6)
                   AND l.shard_key<>:guard");
            $q->execute(['org'=>$org,'source'=>$source,
                'guard'=>MysqlMarketSourceWriterGate::SOURCE_GUARD_SHARD]);
            $tokens=$q->fetchAll(PDO::FETCH_COLUMN);
            if(count($tokens)!==1||(int)$tokens[0]<=(int)$row['previous_token']){
                throw new DomainException('New worker lease token not proven.');
            }
            $current=(int)$tokens[0];
            $v=$this->pdo->prepare(
                "SELECT COUNT(*) FROM tn_capital_market_provider_stream_receipts p
                 JOIN tn_capital_market_instrument_stream_trust t
                   ON t.organization_id=p.organization_id
                  AND t.source_id=p.source_id AND t.instrument_id=p.instrument_id
                  AND t.channel=p.channel
                 WHERE p.organization_id=:org AND p.source_id=:source
                   AND p.status='LIVE_QUOTE' AND p.fencing_token=:token
                   AND p.quote_count>0 AND p.last_quality_score>=80
                   AND p.ack_at>:observed AND p.last_live_quote_at>:observed
                   AND t.status='TRUSTED' AND t.recovery_required=0
                   AND t.last_quality_score>=80 AND t.fencing_token=:current");
            $v->execute(['org'=>$org,'source'=>$source,'token'=>$current,
                'current'=>$current,'observed'=>$row['observed_at']]);
            $confirmed=(int)$v->fetchColumn();
            if($confirmed<1)throw new DomainException('Fresh provider ACK/live quote/trust after failover not proven.');
            $this->record($org,$source,'RECOVERED',(int)$row['previous_token'],$current,true);
            $this->pdo->commit();
            return ['status'=>'RECOVERED','source'=>$source,
                'before'=>(int)$row['previous_token'],'after'=>$current,
                'verified_instruments'=>$confirmed,'genuine_external_data_unverified_by_ci'=>true];
        }catch(Throwable $e){
            if($this->pdo->inTransaction())$this->pdo->rollBack();
            throw $e;
        }
    }

    public function provenSince(string $org,string $source,string $start):bool
    {
        $q=$this->pdo->prepare(
            "SELECT i.id FROM tn_capital_market_stream_failover_observations i
             JOIN tn_capital_market_stream_failover_observations r
               ON r.organization_id=i.organization_id AND r.source_id=i.source_id
              AND r.observation='RECOVERED' AND r.healthy=1
              AND r.id>i.id AND r.previous_token=i.previous_token
              AND r.current_token>i.previous_token
             WHERE i.organization_id=:org AND i.source_id=:source
               AND i.observation='INJECTED' AND i.observed_at>=:started
             LIMIT 1");
        $q->execute(['org'=>$org,'source'=>$source,'started'=>$start]);
        return $q->fetchColumn()!==false;
    }

    private function record(string $org,string $source,string $state,
        ?int $before,?int $after,bool $healthy):void
    {
        $digest=hash('sha256',json_encode([$org,$source,$state,$before,$after,$healthy],
            JSON_THROW_ON_ERROR));
        $q=$this->pdo->prepare(
            "INSERT INTO tn_capital_market_stream_failover_observations
             (organization_id,source_id,observation,previous_token,current_token,
              healthy,evidence_digest)
             VALUES (:org,:source,:state,:prior,:current,:healthy,:digest)");
        $q->execute(['org'=>$org,'source'=>$source,'state'=>$state,'prior'=>$before,
            'current'=>$after,'healthy'=>$healthy?1:0,'digest'=>$digest]);
    }

    private function validate(string $org,string $source):void
    {
        foreach([$org,$source] as $v){
            if($v===''||strlen($v)>190||trim($v)!==$v){
                throw new DomainException('Invalid failover scope.');
            }
        }
    }
}
