<?php
declare(strict_types=1);

namespace Domains\CapitalMarkets\Infrastructure\MarketData\Streaming;

use DateTimeImmutable;
use DomainException;
use PDO;
use Throwable;

/**
 * Invalidate previously trusted current states for one tenant/source.
 *
 * This operation updates both the relational trust column AND its JSON
 * representation, which is consumed by the current repository hydrators.
 * A prior owner is forbidden to invalidate a new owner's state: fencing.
 * The coordinator must invoke this on disconnect/staleness or lease loss.
 */
final readonly class StreamingTrustInvalidator
{
    public function __construct(private PDO $pdo){}

    public function invalidate(
        string $organizationId,string $sourceId,string $shard,string $workerId,
        int $fencingToken,string $reason,
    ):int{
        if($organizationId===''||$sourceId===''||$shard===''||$workerId===''||$fencingToken<1
            ||$reason===''||strlen($reason)>100){
            throw new DomainException('Invalid streaming trust invalidation request.');
        }
        if($this->pdo->inTransaction())throw new DomainException('Streaming invalidation requires an isolated transaction.');
        $this->pdo->beginTransaction();
        try{
            $lock=$this->pdo->prepare(
                'SELECT fencing_token FROM tn_capital_market_stream_worker_leases
                 WHERE organization_id=:org AND source_id=:source AND shard_key=:shard
                   AND owner_id=:owner AND fencing_token=:token FOR UPDATE'
            );
            $lock->execute(['org'=>$organizationId,'source'=>$sourceId,'shard'=>$shard,
                'owner'=>$workerId,'token'=>$fencingToken]);
            if($lock->fetchColumn()===false)throw new DomainException('Streaming invalidation fenced by newer owner.');

            $changed=0;
            foreach(['tn_capital_market_states','tn_capital_market_reference_states'] as $table){
                $sql='SELECT '.($table==='tn_capital_market_states'?'venue_id,':'').
                    'instrument_id,state_json FROM '.$table.
                    ' WHERE organization_id=:org AND source_id=:source AND trust_status=\'TRUSTED\' FOR UPDATE';
                $select=$this->pdo->prepare($sql);
                $select->execute(['org'=>$organizationId,'source'=>$sourceId]);
                foreach($select->fetchAll(PDO::FETCH_ASSOC) as $row){
                    $state=json_decode((string)$row['state_json'],true,512,JSON_THROW_ON_ERROR);
                    if(!is_array($state)||array_is_list($state))throw new DomainException('Invalid state JSON.');
                    if($table==='tn_capital_market_states'){
                        $state['quality_status']='UNTRUSTED';
                        $state['trust_status']='UNTRUSTED';
                        $state['quality_score']=0;
                        $flags=$state['quality_flags']??[];
                        $state['quality_flags']=array_values(array_unique(array_merge(is_array($flags)?$flags:[],['SOURCE_DEGRADED'])));
                    }else{
                        $quality=$state['quality']??[];
                        if(!is_array($quality))$quality=[];
                        $quality['status']='UNTRUSTED';
                        $quality['score']=0;
                        $quality['flags']=array_values(array_unique(array_merge(is_array($quality['flags']??null)?$quality['flags']:[],['SOURCE_DEGRADED'])));
                        $state['quality']=$quality;
                    }
                    $state['state_version']=((int)($state['state_version']??0))+1;
                    $state['updated_at']=(new DateTimeImmutable('now',new \DateTimeZone('UTC')))->format(DATE_ATOM);
                    $where=$table==='tn_capital_market_states'
                        ?' AND venue_id=:venue AND instrument_id=:instrument'
                        :' AND instrument_id=:instrument';
                    $update=$this->pdo->prepare('UPDATE '.$table.' SET state_json=:json,trust_status=\'UNTRUSTED\',
                        quality_score=0,state_version=:version,updated_at=UTC_TIMESTAMP(6)
                        WHERE organization_id=:org AND source_id=:source'.$where.' AND trust_status=\'TRUSTED\'');
                    $params=['json'=>json_encode($state,JSON_THROW_ON_ERROR),
                        'version'=>$state['state_version'],'org'=>$organizationId,'source'=>$sourceId,
                        'instrument'=>$row['instrument_id']];
                    if($table==='tn_capital_market_states')$params['venue']=$row['venue_id'];
                    $update->execute($params);
                    $changed+=$update->rowCount();
                }
            }
            $this->pdo->commit();
            return $changed;
        }catch(Throwable $failure){
            if($this->pdo->inTransaction())$this->pdo->rollBack();
            throw $failure;
        }
    }
}
