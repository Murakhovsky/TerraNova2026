<?php
declare(strict_types=1);

namespace Domains\CapitalMarkets\Infrastructure\MarketData\Streaming;

use DomainException;
use PDO;
use Throwable;

/** Independent REST-observed venue contract lifecycle. Never auto-mutates canonical identity. */
final readonly class MysqlDerivativeLifecycleRegistry
{
    public function __construct(private PDO $pdo){}

    /** @param array<string,mixed> $metadata */
    public function observe(string $org,string $source,string $instrument,array $metadata):array
    {
        $this->identity($org,$source,$instrument);
        $status=$metadata['status']??null;
        $type=$metadata['contract_type']??null;
        if(!is_string($status)||strlen($status)>32||$status===''||!is_string($type)
            ||strlen($type)>64||$type!=='LinearPerpetual'
            ||!in_array($status,['Trading','PreLaunch','Delivering','Settling','Closed'],true)){
            throw new DomainException('Unknown derivative contract status or contract family.');
        }
        if($this->pdo->inTransaction())throw new DomainException('Lifecycle update must be isolated.');
        $digest=hash('sha256',json_encode($metadata,JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES));
        $this->pdo->beginTransaction();
        try{
            MysqlMarketSourceWriterGate::lockSource($this->pdo,$org,$source);
            $q=$this->pdo->prepare(
                'SELECT provider_status,revision FROM tn_capital_market_derivative_contract_status
                 WHERE organization_id=:org AND source_id=:source AND instrument_id=:instrument FOR UPDATE');
            $q->execute(['org'=>$org,'source'=>$source,'instrument'=>$instrument]);
            $old=$q->fetch(PDO::FETCH_ASSOC);
            $changed=!is_array($old)||$old['provider_status']!==$status;
            $revision=is_array($old)?(int)$old['revision']+($changed?1:0):1;
            $write=$this->pdo->prepare(
                "INSERT INTO tn_capital_market_derivative_contract_status
                 (organization_id,source_id,instrument_id,provider_status,contract_type,
                  tradable,observed_at,revision,evidence_sha256)
                 VALUES (:org,:source,:instrument,:status,:type,:tradable,
                         UTC_TIMESTAMP(6),:revision,:digest)
                 ON DUPLICATE KEY UPDATE provider_status=VALUES(provider_status),
                   contract_type=VALUES(contract_type),tradable=VALUES(tradable),
                   observed_at=UTC_TIMESTAMP(6),revision=VALUES(revision),
                   evidence_sha256=VALUES(evidence_sha256)");
            $write->execute(['org'=>$org,'source'=>$source,'instrument'=>$instrument,
                'status'=>$status,'type'=>$type,'tradable'=>$status==='Trading'?1:0,
                'revision'=>$revision,'digest'=>$digest]);
            if($changed){
                $history=$this->pdo->prepare(
                    "INSERT INTO tn_capital_market_derivative_contract_transitions
                     (organization_id,source_id,instrument_id,old_status,new_status,
                      observed_at,evidence_sha256)
                     VALUES (:org,:source,:instrument,:previous,:next,UTC_TIMESTAMP(6),:digest)");
                $history->execute(['org'=>$org,'source'=>$source,'instrument'=>$instrument,
                    'previous'=>$old['provider_status']??null,'next'=>$status,'digest'=>$digest]);
            }
            // Prevent reusing old funding/mark/index/OI observations after
            // delivery, delisting or a paused provider contract.
            if($status!=='Trading'){
                $stop=$this->pdo->prepare(
                    "UPDATE tn_capital_market_derivative_stream_trust
                     SET status='UNTRUSTED',quality_score=0,updated_at=UTC_TIMESTAMP(6)
                     WHERE organization_id=:org AND source_id=:source AND instrument_id=:instrument");
                $stop->execute(['org'=>$org,'source'=>$source,'instrument'=>$instrument]);
                // Durable canonical states must not remain TRUSTED after the
                // venue says this contract is no longer Trading.
                foreach(['tn_capital_market_states','tn_capital_market_reference_states'] as $table){
                    $invalidate=$this->pdo->prepare(
                        "UPDATE $table SET trust_status='UNTRUSTED',quality_score=0,
                         state_json=JSON_SET(state_json,
                           '$.trust_status','UNTRUSTED','$.quality_status','UNTRUSTED',
                           '$.quality.status','UNTRUSTED','$.quality.score',0),
                         state_version=state_version+1,updated_at=UTC_TIMESTAMP(6)
                         WHERE organization_id=:org AND source_id=:source
                           AND instrument_id=:instrument");
                    $invalidate->execute(['org'=>$org,'source'=>$source,'instrument'=>$instrument]);
                }
            }
            $this->pdo->commit();
            return ['provider_status'=>$status,'tradable'=>$status==='Trading',
                'changed'=>$changed,'revision'=>$revision,'evidence_sha256'=>$digest];
        }catch(Throwable $e){
            if($this->pdo->inTransaction())$this->pdo->rollBack();
            throw $e;
        }
    }

    public function assertTrading(string $org,string $source,string $instrument,int $maxAge=300):void
    {
        $this->identity($org,$source,$instrument);
        if($maxAge<30||$maxAge>3600)throw new DomainException('Invalid lifecycle maximum age.');
        $q=$this->pdo->prepare(
            "SELECT 1 FROM tn_capital_market_derivative_contract_status
             WHERE organization_id=:org AND source_id=:source AND instrument_id=:instrument
               AND provider_status='Trading' AND contract_type='LinearPerpetual'
               AND tradable=1
               AND observed_at>=DATE_SUB(UTC_TIMESTAMP(6),INTERVAL :age SECOND)");
        $q->bindValue(':org',$org);
        $q->bindValue(':source',$source);
        $q->bindValue(':instrument',$instrument);
        $q->bindValue(':age',$maxAge,PDO::PARAM_INT);
        $q->execute();
        if($q->fetchColumn()===false){
            throw new DomainException('Derivative contract not independently confirmed tradable.');
        }
    }

    private function identity(string ...$values):void
    {
        foreach($values as $value)if($value===''||trim($value)!==$value||strlen($value)>190){
            throw new DomainException('Invalid derivative tenant/source/instrument identity.');
        }
    }
}
