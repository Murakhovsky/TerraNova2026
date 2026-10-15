<?php
declare(strict_types=1);

namespace Domains\CapitalMarkets\Infrastructure\Agent;

use DomainException;
use PDO;
use RuntimeException;
use Throwable;

/**
 * MySQL-backed idempotent run and per-stage checkpoint journal.
 *
 * Transactional owner-token fencing applies to every checkpoint and finish.
 * No DB transaction is held across an LLM or tool call. RUNNING attempts are
 * not silently stolen. A human must explicitly request recovery of a run
 * whose last checkpoint is more than 30 minutes old.
 *
 * Does not claim exactly-once LLM billing or external tool side effects
 * between an agent result and its durable checkpoint.
 */
final readonly class MysqlCapitalMarketsAgentCycleJournal
{
    public function __construct(private PDO $pdo){}

    /**
     * @return array{kind:string,token?:string,stages?:array,result?:array}
     */
    public function begin(
        string $org,string $correlation,string $opportunity,string $question,bool $resume=false,
    ):array{
        if($this->pdo->inTransaction())throw new DomainException('Agent journal requires its own transaction.');
        $hash=hash('sha256',json_encode([$org,$opportunity,$question],JSON_THROW_ON_ERROR));
        $newToken=bin2hex(random_bytes(16));
        $this->pdo->beginTransaction();
        try{
            $insert=$this->pdo->prepare(
                "INSERT IGNORE INTO tn_capital_market_agent_cycles
                  (organization_id,correlation_id,opportunity_id,input_hash,owner_token,state,stages_json)
                  VALUES (:org,:corr,:opp,:hash,:owner,'RUNNING','[]')"
            );
            $insert->execute([
                'org'=>$org,'corr'=>$correlation,'opp'=>$opportunity,
                'hash'=>$hash,'owner'=>$newToken,
            ]);
            $row=$this->lock($org,$correlation);
            if($row===null)throw new RuntimeException('Unable to create agent journal.');
            if(!hash_equals((string)$row['input_hash'],$hash)
                ||(string)$row['opportunity_id']!==$opportunity){
                throw new DomainException('Correlation id belongs to different agent-cycle input.');
            }
            $stages=$this->jsonList((string)$row['stages_json']);
            $state=(string)$row['state'];
            if($insert->rowCount()===1){
                $this->pdo->commit();
                return ['kind'=>'ACQUIRED','token'=>$newToken,'stages'=>[]];
            }
            if(in_array($state,['BLOCKED','REVIEW_REQUIRED','AWAITING_EXECUTION_EVIDENCE'],true)
                &&!($resume&&$state==='AWAITING_EXECUTION_EVIDENCE')){
                $result=$row['result_json']===null?null:json_decode((string)$row['result_json'],true,64,JSON_THROW_ON_ERROR);
                if(!is_array($result))throw new RuntimeException('Completed agent cycle has no persisted result.');
                $this->pdo->commit();
                return ['kind'=>'CACHED','result'=>$result];
            }
            if($state==='RUNNING'){
                $stale=strtotime((string)$row['updated_at'].' UTC')<=time()-1800;
                if(!$resume||!$stale){
                    $this->pdo->commit();
                    return ['kind'=>'IN_PROGRESS'];
                }
            }elseif($state==='FAILED'&&!$resume){
                $this->pdo->commit();
                return ['kind'=>'RECOVERY_REQUIRED'];
            }elseif(!in_array($state,['FAILED','AWAITING_EXECUTION_EVIDENCE'],true)){
                throw new DomainException('Agent cycle cannot be resumed from its current state.');
            }

            $takeover=$this->pdo->prepare(
                "UPDATE tn_capital_market_agent_cycles
                 SET owner_token=:new_owner,state='RUNNING',result_json=NULL,
                     attempt=attempt+1,updated_at=UTC_TIMESTAMP(6)
                 WHERE organization_id=:org AND correlation_id=:corr AND owner_token=:old_owner"
            );
            $takeover->execute([
                'new_owner'=>$newToken,'org'=>$org,'corr'=>$correlation,
                'old_owner'=>(string)$row['owner_token'],
            ]);
            if($takeover->rowCount()!==1)throw new RuntimeException('Agent cycle takeover lost owner CAS.');
            $this->pdo->commit();
            return ['kind'=>'ACQUIRED','token'=>$newToken,'stages'=>$stages];
        }catch(Throwable $error){
            if($this->pdo->inTransaction())$this->pdo->rollBack();
            throw $error;
        }
    }

    /** @param array<string,mixed> $stage */
    public function checkpoint(
        string $org,string $correlation,string $token,array $stage,int $expectedIndex,
    ):void{
        $this->transaction(function()use($org,$correlation,$token,$stage,$expectedIndex):void{
            $row=$this->owned($org,$correlation,$token);
            $stages=$this->jsonList((string)$row['stages_json']);
            if(count($stages)!==$expectedIndex)throw new DomainException('Agent checkpoint index is stale.');
            $names=['Observe','Research','Detect','Assess','Allocate','Execute','Measure','Learn'];
            if(($stage['stage']??null)!==($names[$expectedIndex]??null)
                ||!is_string($stage['run_id']??null)||$stage['run_id']===''){
                throw new DomainException('Agent checkpoint stage or run id is invalid.');
            }
            if(($stage['action_count']??0)!==0)throw new DomainException('Agent checkpoint cannot store business actions.');
            $stages[]=$stage;
            $query=$this->pdo->prepare(
                "UPDATE tn_capital_market_agent_cycles
                 SET stages_json=:stages,updated_at=UTC_TIMESTAMP(6)
                 WHERE organization_id=:org AND correlation_id=:corr
                   AND owner_token=:owner AND state='RUNNING'"
            );
            $query->execute([
                'stages'=>json_encode($stages,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE),
                'org'=>$org,'corr'=>$correlation,'owner'=>$token,
            ]);
            if($query->rowCount()!==1)throw new RuntimeException('Agent checkpoint owner fence rejected.');
        });
    }

    /** @param array<string,mixed> $result */
    public function finish(string $org,string $correlation,string $token,array $result):void
    {
        $status=(string)($result['status']??'');
        if(!in_array($status,['BLOCKED','REVIEW_REQUIRED','AWAITING_EXECUTION_EVIDENCE'],true)){
            throw new DomainException('Invalid agent cycle terminal status.');
        }
        $this->transaction(function()use($org,$correlation,$token,$result,$status):void{
            $row=$this->owned($org,$correlation,$token);
            $stages=$this->jsonList((string)$row['stages_json']);
            if(count($stages)!==(int)($result['stage_count']??-1)){
                throw new DomainException('Agent stage journal/result count mismatch.');
            }
            $this->updateState($org,$correlation,$token,$status,$result);
        });
    }

    public function failed(string $org,string $correlation,string $token):void
    {
        $this->transaction(function()use($org,$correlation,$token):void{
            $this->owned($org,$correlation,$token);
            $this->updateState($org,$correlation,$token,'FAILED',null);
        });
    }

    /** @return array<string,mixed>|null */
    public function snapshot(string $org,string $correlation):?array
    {
        $read=$this->pdo->prepare(
            'SELECT organization_id,correlation_id,opportunity_id,state,stages_json,result_json,attempt,started_at,updated_at
             FROM tn_capital_market_agent_cycles WHERE organization_id=:org AND correlation_id=:corr'
        );
        $read->execute(['org'=>$org,'corr'=>$correlation]);
        $row=$read->fetch(PDO::FETCH_ASSOC);
        if(!is_array($row))return null;
        $row['stages']=$this->jsonList((string)$row['stages_json']);
        unset($row['stages_json']);
        $row['result']=$row['result_json']===null?null:json_decode((string)$row['result_json'],true,64,JSON_THROW_ON_ERROR);
        unset($row['result_json']);
        return $row;
    }

    private function updateState(string $org,string $corr,string $token,string $state,?array $result):void
    {
        $q=$this->pdo->prepare(
            'UPDATE tn_capital_market_agent_cycles
             SET state=:state,result_json=:result,updated_at=UTC_TIMESTAMP(6)
             WHERE organization_id=:org AND correlation_id=:corr
               AND owner_token=:owner AND state=\'RUNNING\''
        );
        $q->execute([
            'state'=>$state,'result'=>$result===null?null:json_encode($result,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE),
            'org'=>$org,'corr'=>$corr,'owner'=>$token,
        ]);
        if($q->rowCount()!==1)throw new RuntimeException('Agent cycle finalization lost its owner fence.');
    }

    /** @return array<string,mixed>|null */
    private function lock(string $org,string $correlation):?array
    {
        $query=$this->pdo->prepare(
            'SELECT * FROM tn_capital_market_agent_cycles
             WHERE organization_id=:org AND correlation_id=:corr FOR UPDATE'
        );
        $query->execute(['org'=>$org,'corr'=>$correlation]);
        $row=$query->fetch(PDO::FETCH_ASSOC);
        return is_array($row)?$row:null;
    }

    /** @return array<string,mixed> */
    private function owned(string $org,string $correlation,string $token):array
    {
        $row=$this->lock($org,$correlation);
        if($row===null||!hash_equals((string)$row['owner_token'],$token)
            ||$row['state']!=='RUNNING'){
            throw new DomainException('Agent cycle does not own the current durable run.');
        }
        return $row;
    }

    /** @return list<array<string,mixed>> */
    private function jsonList(string $json):array
    {
        $decoded=json_decode($json,true,64,JSON_THROW_ON_ERROR);
        if(!is_array($decoded)||!array_is_list($decoded))throw new RuntimeException('Corrupt agent checkpoint JSON.');
        foreach($decoded as $row)if(!is_array($row)||array_is_list($row)){
            throw new RuntimeException('Corrupt agent checkpoint stage.');
        }
        return $decoded;
    }

    private function transaction(callable $callback):void
    {
        if($this->pdo->inTransaction())throw new DomainException('Nested agent journal transaction forbidden.');
        $this->pdo->beginTransaction();
        try{$callback();$this->pdo->commit();}
        catch(Throwable $e){if($this->pdo->inTransaction())$this->pdo->rollBack();throw $e;}
    }
}
