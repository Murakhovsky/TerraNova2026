<?php
declare(strict_types=1);
namespace Domains\Marketing\Infrastructure\Persistence\MySql;

use DomainException;
use Domains\Marketing\Application\Contract\MarketingAnalystRunStore;
use PDO;
use Throwable;

/** Serialize admissions per tenant; never hold the database transaction during a remote turn. */
final readonly class MysqlMarketingAnalystRunStore implements MarketingAnalystRunStore
{
    public function __construct(private PDO $connection){}

    public function byKey(string $org,string $key):?array
    {
        return $this->read('idempotency_key',$org,$key);
    }

    public function claim(string $org,string $key,array $claim,int $dailyLimit):array
    {
        $db=$this->connection;
        if($db->inTransaction())throw new DomainException('Nested analyst admission transaction denied.');
        try{
            $db->beginTransaction();
            $db->prepare('INSERT IGNORE INTO marketing_analyst_admission_counters
                (organization_id,quota_day,admitted_count) VALUES (?,UTC_DATE(),0)')->execute([$org]);
            $q=$db->prepare('SELECT quota_day,admitted_count,UTC_DATE() AS today FROM marketing_analyst_admission_counters
                WHERE organization_id=? FOR UPDATE');
            $q->execute([$org]);$counter=$q->fetch(PDO::FETCH_ASSOC);
            if(!is_array($counter))throw new DomainException('Analyst quota unavailable.');
            $existing=$this->read('idempotency_key',$org,$key,true);
            if($existing!==null){
                if(!hash_equals($existing['request_hash'],$claim['request_hash']))throw new DomainException('Analyst idempotency conflict.');
                $db->commit();return ['claimed'=>false,'run'=>$existing];
            }
            $q=$db->prepare("SELECT id FROM marketing_analyst_runs WHERE organization_id=? AND state='STARTED' LIMIT 1 FOR UPDATE");
            $q->execute([$org]);
            if($q->fetchColumn()!==false)throw new DomainException('Unresolved analyst execution; reconcile before a new turn.');
            $count=$counter['quota_day']===$counter['today']?(int)$counter['admitted_count']:0;
            if($count>=$dailyLimit)throw new DomainException('Daily analyst admission quota exhausted.');
            $id='mkar-'.bin2hex(random_bytes(12));
            $db->prepare("INSERT INTO marketing_analyst_runs (organization_id,id,idempotency_key,request_hash,requested_by,
                profile_ref,cost_control_ref,campaign_id,context_hash,source_pins_json,state,quota_day)
                VALUES (?,?,?,?,?,?,?,?,?,?,'STARTED',?)")->execute([
                $org,$id,$key,$claim['request_hash'],$claim['requested_by'],$claim['profile_ref'],$claim['cost_control_ref'],
                $claim['campaign_id'],$claim['context_hash'],self::json($claim['source_pins']),$counter['today']]);
            $db->prepare('UPDATE marketing_analyst_admission_counters SET quota_day=?,admitted_count=? WHERE organization_id=?')
                ->execute([$counter['today'],$count+1,$org]);
            $run=$this->read('id',$org,$id);
            if($run===null)throw new DomainException('Analyst admission receipt unavailable.');
            $db->commit();return ['claimed'=>true,'run'=>$run];
        }catch(Throwable $e){
            if($db->inTransaction())$db->rollBack();
            throw $e;
        }
    }

    public function finish(string $org,string $id,?array $advice):array
    {
        if($this->connection->inTransaction())throw new DomainException('Nested analyst completion denied.');
        $q=$this->connection->prepare("UPDATE marketing_analyst_runs SET state=?,advice_json=?,failure_code=?,completed_at=UTC_TIMESTAMP(6)
            WHERE organization_id=? AND id=? AND state='STARTED'");
        $q->execute([$advice===null?'UNCERTAIN':'SUCCEEDED',$advice===null?null:self::json($advice),
            $advice===null?'REMOTE_OUTCOME_UNCERTAIN':null,$org,$id]);
        if($q->rowCount()!==1)throw new DomainException('Analyst execution is not pending completion.');
        return $this->read('id',$org,$id)??throw new DomainException('Analyst completion receipt unavailable.');
    }

    /** @return array<string,mixed>|null */
    private function read(string $field,string $org,string $value,bool $lock=false):?array
    {
        if(!in_array($field,['id','idempotency_key'],true))throw new DomainException('Invalid analyst lookup.');
        $q=$this->connection->prepare('SELECT id,request_hash,requested_by,profile_ref,cost_control_ref,campaign_id,context_hash,
            source_pins_json,state,advice_json,failure_code,quota_day,created_at,completed_at FROM marketing_analyst_runs
            WHERE organization_id=? AND '.$field.'=?'.($lock?' FOR UPDATE':''));
        $q->execute([$org,$value]);$row=$q->fetch(PDO::FETCH_ASSOC);
        if(!is_array($row))return null;
        $row['source_pins']=json_decode((string)$row['source_pins_json'],true,32,JSON_THROW_ON_ERROR);
        $row['advice']=$row['advice_json']===null?null:json_decode((string)$row['advice_json'],true,32,JSON_THROW_ON_ERROR);
        unset($row['source_pins_json'],$row['advice_json']);
        $row['finance_cost_confirmed']=false;$row['paid_activation_approved']=false;
        return $row;
    }

    private static function json(array $data):string
    {
        return json_encode($data,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
    }
}
