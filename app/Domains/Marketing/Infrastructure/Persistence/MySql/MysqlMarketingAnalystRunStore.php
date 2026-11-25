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

    public function get(string $org,string $id):?array
    {
        return $this->read('id',$org,$id);
    }

    public function listRuns(string $org,string $campaignId,int $limit=50,int $offset=0):array
    {
        if($limit<1||$limit>100||$offset<0||$offset>1000000)throw new DomainException('Invalid analyst journal bounds.');
        $q=$this->connection->prepare('SELECT id FROM marketing_analyst_runs WHERE organization_id=? AND campaign_id=?
            ORDER BY created_at DESC,id DESC LIMIT '.$limit.' OFFSET '.$offset);
        $q->execute([$org,$campaignId]);
        $rows=[];
        foreach($q->fetchAll(PDO::FETCH_COLUMN) as $id){
            $row=$this->get($org,(string)$id);
            if($row!==null)$rows[]=$row;
        }
        return $rows;
    }

    public function decide(string $org,string $id,string $campaignId,string $actor,string $key,array $decision,callable $validateFresh):array
    {
        $db=$this->connection;
        if($db->inTransaction())throw new DomainException('Nested analyst decision denied.');
        $hash=hash('sha256',self::json([$id,$campaignId,$actor,$decision]));
        try{
            $db->beginTransaction();
            $run=$this->read('id',$org,$id,true);
            if($run===null||$run['campaign_id']!==$campaignId)throw new DomainException('Analyst run unavailable.');
            $q=$db->prepare('SELECT run_id,request_hash FROM marketing_analyst_run_decisions WHERE organization_id=? AND idempotency_key=? FOR UPDATE');
            $q->execute([$org,$key]);$receipt=$q->fetch(PDO::FETCH_ASSOC);
            if(is_array($receipt)){
                if($receipt['run_id']!==$id||!hash_equals($receipt['request_hash'],$hash))throw new DomainException('Analyst decision idempotency conflict.');
                $db->commit();return $run;
            }
            if($run['decision']!==null)throw new DomainException('Analyst decision is already recorded.');
            if($run['requested_by']===$actor)throw new DomainException('Independent manager required.');
            if($decision['decision']==='RECOVERED_UNCERTAIN'){
                if(!in_array($run['state'],['STARTED','UNCERTAIN'],true)||!$run['recovery_age_met']){
                    throw new DomainException('Run is not eligible for manual recovery.');
                }
                if($run['state']==='STARTED'){
                    $q=$db->prepare("UPDATE marketing_analyst_runs SET state='UNCERTAIN',failure_code='OPERATOR_RECOVERED_UNCERTAIN',
                        completed_at=UTC_TIMESTAMP(6) WHERE organization_id=? AND id=? AND state='STARTED'");
                    $q->execute([$org,$id]);
                    if($q->rowCount()!==1)throw new DomainException('Run state changed during recovery.');
                }
            }else{
                if($run['state']!=='SUCCEEDED'||$run['advice']===null)throw new DomainException('No completed advice to review.');
                // Reject can dismiss stale advice; ACCEPT requires current source evidence.
                if($decision['decision']==='ACCEPT'){
                    $this->lockSourcePins($org,$run);
                    $validateFresh($run);
                }
            }
            $db->prepare('INSERT INTO marketing_analyst_run_decisions
                (organization_id,run_id,idempotency_key,request_hash,actor_id,decision,reason,evidence_ref)
                VALUES (?,?,?,?,?,?,?,?)')->execute([$org,$id,$key,$hash,$actor,$decision['decision'],$decision['reason'],$decision['evidence_ref']??null]);
            $result=$this->read('id',$org,$id);
            if($result===null)throw new DomainException('Decision receipt unavailable.');
            $db->commit();return $result;
        }catch(Throwable $e){
            if($db->inTransaction())$db->rollBack();
            throw $e;
        }
    }

    /** Lock Marketing-owned records in Campaign -> Audience -> Creative -> AdSet order. */
    private function lockSourcePins(string $org,array $run):void
    {
        $pins=array_column($run['source_pins'],null,'alias');
        foreach(['campaign'=>'marketing_campaigns','audience'=>'marketing_audiences',
            'creative'=>'marketing_creative_briefs','adset'=>'marketing_adset_drafts'] as $alias=>$table){
            if(!isset($pins[$alias]))continue;
            $pin=$pins[$alias];
            $q=$this->connection->prepare('SELECT revision,status FROM '.$table.' WHERE organization_id=? AND id=? FOR UPDATE');
            $q->execute([$org,$pin['record_id']]);$row=$q->fetch(PDO::FETCH_ASSOC);
            if(!is_array($row)||$row['status']!=='DRAFT'||(int)$row['revision']!==$pin['revision']){
                throw new DomainException('Marketing source revision is stale.');
            }
        }
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
            $q=$db->prepare("SELECT r.id FROM marketing_analyst_runs r LEFT JOIN marketing_analyst_run_decisions d
                ON d.organization_id=r.organization_id AND d.run_id=r.id
                WHERE r.organization_id=? AND r.state IN ('STARTED','UNCERTAIN') AND d.run_id IS NULL LIMIT 1 FOR UPDATE");
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
        $q=$this->connection->prepare('SELECT r.id,r.request_hash,r.requested_by,r.profile_ref,r.cost_control_ref,r.campaign_id,r.context_hash,
            r.source_pins_json,r.state,r.advice_json,r.failure_code,r.quota_day,r.created_at,r.completed_at,
            (r.created_at<=CURRENT_TIMESTAMP(6)-INTERVAL 15 MINUTE) AS recovery_age_met,
            d.decision,d.actor_id AS decision_actor_id,d.reason AS decision_reason,d.evidence_ref AS recovery_evidence_ref,d.created_at AS decision_at
            FROM marketing_analyst_runs r LEFT JOIN marketing_analyst_run_decisions d ON d.organization_id=r.organization_id AND d.run_id=r.id
            WHERE r.organization_id=? AND r.'.$field.'=?'.($lock?' FOR UPDATE':''));
        $q->execute([$org,$value]);$row=$q->fetch(PDO::FETCH_ASSOC);
        if(!is_array($row))return null;
        $row['source_pins']=json_decode((string)$row['source_pins_json'],true,32,JSON_THROW_ON_ERROR);
        $row['advice']=$row['advice_json']===null?null:json_decode((string)$row['advice_json'],true,32,JSON_THROW_ON_ERROR);
        unset($row['source_pins_json'],$row['advice_json']);
        $row['recovery_age_met']=(bool)$row['recovery_age_met'];
        $row['finance_cost_confirmed']=false;$row['paid_activation_approved']=false;
        return $row;
    }

    private static function json(array $data):string
    {
        return json_encode($data,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
    }
}
