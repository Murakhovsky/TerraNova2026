<?php
declare(strict_types=1);

namespace Domains\CapitalMarkets\Infrastructure\MarketData\Streaming;

use DateTimeImmutable;
use DomainException;
use Domains\CapitalMarkets\Application\Contract\StreamingInstrumentTrustInvalidatorInterface;
use Domains\CapitalMarkets\Application\Contract\StreamingTrustInvalidatorInterface;
use PDO;
use Throwable;

/**
 * Fenced trust revocation for a complete source or one instrument.
 *
 * Source-wide failures also degrade source health. Instrument-only failures
 * mutate just that instrument's trading/reference states and leave other
 * instruments plus transport health untouched.
 */
final readonly class StreamingTrustInvalidator implements
    StreamingTrustInvalidatorInterface,
    StreamingInstrumentTrustInvalidatorInterface
{
    public function __construct(private PDO $pdo){}

    public function invalidate(
        string $organizationId,string $sourceId,string $shard,string $workerId,
        int $fencingToken,string $reason,
    ):int{
        return $this->invalidateScope(
            $organizationId,$sourceId,$shard,$workerId,$fencingToken,null,$reason,
        );
    }

    public function invalidateInstrument(
        string $organizationId,string $sourceId,string $shard,string $workerId,
        int $fencingToken,string $instrumentId,string $reason,
    ):int{
        if(trim($instrumentId)===''||strlen($instrumentId)>190){
            throw new DomainException('Invalid instrument trust invalidation target.');
        }
        return $this->invalidateScope(
            $organizationId,$sourceId,$shard,$workerId,$fencingToken,$instrumentId,$reason,
        );
    }

    private function invalidateScope(
        string $organizationId,string $sourceId,string $shard,string $workerId,
        int $fencingToken,?string $instrumentId,string $reason,
    ):int{
        if($organizationId===''||$sourceId===''||$shard===''||$workerId===''||$fencingToken<1
            ||$reason===''||strlen($reason)>100||preg_match('/^[A-Z0-9_]+$/D',$reason)!==1){
            throw new DomainException('Invalid streaming trust invalidation request.');
        }
        if($this->pdo->inTransaction()){
            throw new DomainException('Streaming invalidation requires an isolated transaction.');
        }
        $this->pdo->beginTransaction();
        try{
            $lock=$this->pdo->prepare(
                'SELECT fencing_token FROM tn_capital_market_stream_worker_leases
                 WHERE organization_id=:org AND source_id=:source AND shard_key=:shard
                   AND owner_id=:owner AND fencing_token=:token
                   AND expires_at > UTC_TIMESTAMP(6) FOR UPDATE'
            );
            $lock->execute([
                'org'=>$organizationId,'source'=>$sourceId,'shard'=>$shard,
                'owner'=>$workerId,'token'=>$fencingToken,
            ]);
            if($lock->fetchColumn()===false){
                throw new DomainException('Streaming invalidation fenced by expired or newer owner.');
            }

            $changed=0;
            $scopeSql=$instrumentId===null?'':' AND instrument_id=:instrument';
            $scopeParams=$instrumentId===null?[]:['instrument'=>$instrumentId];
            $flag=$instrumentId===null?'SOURCE_DEGRADED':'INSTRUMENT_UNTRUSTED';
            foreach(['tn_capital_market_states','tn_capital_market_reference_states'] as $table){
                $selectSql='SELECT '.($table==='tn_capital_market_states'?'venue_id,':'').
                    'instrument_id,state_json FROM '.$table.
                    ' WHERE organization_id=:org AND source_id=:source'.$scopeSql.
                    " AND trust_status IN ('TRUSTED','DEGRADED') FOR UPDATE";
                $select=$this->pdo->prepare($selectSql);
                $select->execute(array_merge(['org'=>$organizationId,'source'=>$sourceId],$scopeParams));
                foreach($select->fetchAll(PDO::FETCH_ASSOC) as $row){
                    $state=json_decode((string)$row['state_json'],true,512,JSON_THROW_ON_ERROR);
                    if(!is_array($state)||array_is_list($state)){
                        throw new DomainException('Invalid market state JSON during trust invalidation.');
                    }
                    if($table==='tn_capital_market_states'){
                        $state['quality_status']='UNTRUSTED';
                        $state['trust_status']='UNTRUSTED';
                        $state['quality_score']=0;
                        $flags=$state['quality_flags']??[];
                        $state['quality_flags']=array_values(array_unique(array_merge(
                            is_array($flags)?$flags:[],[$flag,'STREAM_'.$reason],
                        )));
                    }else{
                        $quality=$state['quality']??[];
                        if(!is_array($quality))$quality=[];
                        $quality['status']='UNTRUSTED';
                        $quality['score']=0;
                        $quality['flags']=array_values(array_unique(array_merge(
                            is_array($quality['flags']??null)?$quality['flags']:[],[$flag,'STREAM_'.$reason],
                        )));
                        $state['quality']=$quality;
                    }
                    $state['state_version']=((int)($state['state_version']??0))+1;
                    $state['updated_at']=(new DateTimeImmutable('now',new \DateTimeZone('UTC')))->format(DATE_ATOM);
                    $where=$table==='tn_capital_market_states'
                        ?' AND venue_id=:venue AND instrument_id=:instrument'
                        :' AND instrument_id=:instrument';
                    $update=$this->pdo->prepare(
                        "UPDATE ".$table." SET state_json=:json,trust_status='UNTRUSTED',
                         quality_score=0,state_version=:version,updated_at=UTC_TIMESTAMP(6)
                         WHERE organization_id=:org AND source_id=:source".$where.
                         " AND trust_status IN ('TRUSTED','DEGRADED')"
                    );
                    $params=[
                        'json'=>json_encode($state,JSON_THROW_ON_ERROR),
                        'version'=>$state['state_version'],'org'=>$organizationId,
                        'source'=>$sourceId,'instrument'=>$row['instrument_id'],
                    ];
                    if($table==='tn_capital_market_states')$params['venue']=$row['venue_id'];
                    $update->execute($params);
                    $changed+=$update->rowCount();
                }
            }

            // Revoke durable per-symbol recovery too: stale recovered streams
            // cannot be admitted from an older lease/epoch after quarantine.
            $trust=$this->pdo->prepare(
                'UPDATE tn_capital_market_instrument_stream_trust
                 SET status=\'UNTRUSTED\',recovery_required=1,
                     reason=:reason,last_quality_score=0,updated_at=UTC_TIMESTAMP(6)
                 WHERE organization_id=:org AND source_id=:source'.
                 ($instrumentId===null?'':' AND instrument_id=:instrument')
            );
            $trust->execute(array_merge([
                'reason'=>$flag,'org'=>$organizationId,'source'=>$sourceId,
            ],$scopeParams));

            // A single ticker problem is not a transport outage.
            if($instrumentId===null){
                $health=$this->pdo->prepare(
                    "UPDATE tn_capital_market_source_health
                     SET connection_state='DEGRADED',last_error=:reason,
                         failure_count=failure_count+1,error_count=error_count+1
                     WHERE organization_id=:org AND source_id=:source"
                );
                $health->execute([
                    'reason'=>'STREAM_'.$reason,'org'=>$organizationId,'source'=>$sourceId,
                ]);
            }
            $this->pdo->commit();
            return $changed;
        }catch(Throwable $failure){
            if($this->pdo->inTransaction())$this->pdo->rollBack();
            throw $failure;
        }
    }
}
