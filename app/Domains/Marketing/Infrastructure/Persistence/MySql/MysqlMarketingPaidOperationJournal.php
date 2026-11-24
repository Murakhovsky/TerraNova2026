<?php
declare(strict_types=1);
namespace Domains\Marketing\Infrastructure\Persistence\MySql;

use DomainException;
use PDO;

/** Durable per-operation one-shot admission; never authorizes a provider call by itself. */
final readonly class MysqlMarketingPaidOperationJournal
{
    public const OPERATIONS=['DEPLOY_PAUSED','ACTIVATE','PAUSE','CREATE_CAMPAIGN','CREATE_ADSET','CREATE_AD','ACTIVATE_AD','ACTIVATE_ADSET','ACTIVATE_CAMPAIGN','PAUSE_CAMPAIGN'];
    public function __construct(private PDO $db){}
    /** Serialize command admission on native plan row, not across a remote call. */
    public function beginCommand(string $org,string $plan,string $operation,string $action,string $hash):array
    {
        if(!in_array($operation,['DEPLOY_PAUSED','ACTIVATE','PAUSE'],true)||$this->db->inTransaction())throw new DomainException('Invalid command admission.');
        $this->db->beginTransaction();
        try{
            $q=$this->db->prepare('SELECT id FROM marketing_paid_launch_plans WHERE organization_id=? AND id=? FOR UPDATE');
            $q->execute([$org,$plan]);if($q->fetchColumn()!==$plan)throw new DomainException('Native paid plan unavailable.');
            $existing=$this->get($org,$plan,$operation);
            if($existing!==null){
                if($existing['action_id']!==$action||!hash_equals($existing['request_hash'],$hash))throw new DomainException('Command already claimed.');
                $this->db->commit();return [...$existing,'fresh'=>false];
            }
            foreach(['DEPLOY_PAUSED','ACTIVATE','PAUSE'] as $other){
                $row=$this->get($org,$plan,$other);
                if($row!==null&&$row['state']!=='CONFIRMED')throw new DomainException('Unresolved provider command; manual reconciliation required.');
            }
            if($operation==='ACTIVATE'&&($this->get($org,$plan,'DEPLOY_PAUSED')['state']??null)!=='CONFIRMED')throw new DomainException('Verified paused deployment required.');
            if($operation==='ACTIVATE'&&$this->get($org,$plan,'PAUSE')!==null)throw new DomainException('Paused plan cannot be reactivated.');
            if($operation==='PAUSE'&&($this->get($org,$plan,'DEPLOY_PAUSED')['state']??null)!=='CONFIRMED')throw new DomainException('Known campaign binding required.');
            $q=$this->db->prepare('INSERT INTO marketing_paid_operation_journal (organization_id,plan_id,operation,action_id,request_hash) VALUES (?,?,?,?,?)');
            $q->execute([$org,$plan,$operation,$action,$hash]);
            $row=$this->get($org,$plan,$operation)??throw new DomainException('Command receipt missing.');
            $this->db->commit();return [...$row,'fresh'=>true];
        }catch(\Throwable $e){if($this->db->inTransaction())$this->db->rollBack();throw $e;}
    }

    public function begin(string $org,string $plan,string $operation,string $action,string $hash):array
    {
        if(!in_array($operation,self::OPERATIONS,true)||!preg_match('/^mkpl-[a-f0-9]{24}$/D',$plan)
            ||!preg_match('/^[a-f0-9]{32}$/D',$action)||!preg_match('/^[a-f0-9]{64}$/D',$hash)
            ||$this->db->inTransaction())throw new DomainException('Invalid provider operation admission.');
        $q=$this->db->prepare('INSERT IGNORE INTO marketing_paid_operation_journal
            (organization_id,plan_id,operation,action_id,request_hash) VALUES (?,?,?,?,?)');
        $q->execute([$org,$plan,$operation,$action,$hash]);$fresh=$q->rowCount()===1;
        $row=$this->get($org,$plan,$operation);
        if($row===null||!hash_equals($hash,$row['request_hash'])||$row['action_id']!==$action)
            throw new DomainException('Provider operation already claimed or plan missing.');
        return [...$row,'fresh'=>$fresh];
    }
    public function identify(string $org,string $plan,string $operation,string $action,string $providerId):void
    {
        if(!preg_match('/^[0-9]{1,30}$/D',$providerId))throw new DomainException('Invalid Meta receipt ID.');
        $q=$this->db->prepare("UPDATE marketing_paid_operation_journal SET provider_id=?
            WHERE organization_id=? AND plan_id=? AND operation=? AND action_id=? AND state='STARTED' AND provider_id IS NULL");
        $q->execute([$providerId,$org,$plan,$operation,$action]);
        if($q->rowCount()!==1)throw new DomainException('Provider receipt identity already set or operation missing.');
    }
    public function finish(string $org,string $plan,string $operation,string $action,?array $receipt):void
    {
        $state=$receipt===null?'UNCERTAIN':'CONFIRMED';
        $json=$receipt===null?null:json_encode($receipt,JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES);
        if($json!==null&&strlen($json)>8192)throw new DomainException('Provider receipt exceeds bounds.');
        $q=$this->db->prepare("UPDATE marketing_paid_operation_journal SET state=?,receipt_json=?,completed_at=UTC_TIMESTAMP(6)
            WHERE organization_id=? AND plan_id=? AND operation=? AND action_id=? AND state='STARTED'
            AND (?='UNCERTAIN' OR provider_id IS NOT NULL)");
        $q->execute([$state,$json,$org,$plan,$operation,$action,$state]);
        if($q->rowCount()!==1)throw new DomainException('Provider operation completion conflict.');
    }
    public function get(string $org,string $plan,string $operation):?array
    {
        $q=$this->db->prepare('SELECT operation,action_id,request_hash,state,provider_id,receipt_json,created_at,completed_at
            FROM marketing_paid_operation_journal WHERE organization_id=? AND plan_id=? AND operation=?');
        $q->execute([$org,$plan,$operation]);$row=$q->fetch(PDO::FETCH_ASSOC);
        if(!is_array($row))return null;
        $row['receipt']=$row['receipt_json']===null?null:json_decode($row['receipt_json'],true,16,JSON_THROW_ON_ERROR);
        unset($row['receipt_json']);return $row;
    }
}
