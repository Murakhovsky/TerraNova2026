<?php
declare(strict_types=1);

namespace Domains\Sales\Infrastructure\Persistence\MySql;

use Domains\Sales\Application\Contract\LeadFollowupRepositoryInterface;
use Domains\Sales\Application\DTO\OperationResult;
use Domains\Sales\Application\DTO\ScheduleLeadFollowupCommand;
use PDO;
use DomainException;
use Throwable;

final readonly class MysqlLeadFollowupRepository implements LeadFollowupRepositoryInterface
{
    private const OPERATION='lead_followup';
    public function __construct(private PDO $connection){}
    public function schedule(ScheduleLeadFollowupCommand $command):OperationResult
    {
        $owns=!$this->connection->inTransaction();
        if($owns)$this->connection->beginTransaction();
        try{
            $org=$command->organizationId;$lead=$command->leadReference;$key=$command->idempotencyKey;
            if(!ctype_digit($lead)||(int)$lead<1||(string)(int)$lead!==$lead||trim($key)===''||strlen($key)>191||trim($command->title)===''||mb_strlen($command->title)>180||mb_strlen($command->body??'')>4000)throw new DomainException('Invalid lead followup.');
            $q=$this->connection->prepare('SELECT status FROM tn_leads WHERE organization_id=? AND id=? FOR UPDATE');
            $q->execute([$org,$lead]);$status=$q->fetchColumn();
            if($status===false)throw new DomainException('Tenant-owned lead not found.');
            $due=$command->dueAt->format('Y-m-d H:i:s');
            $hash=hash('sha256',json_encode([$lead,$command->title,$command->body,$due],JSON_THROW_ON_ERROR));
            $receipts=new MysqlSalesMutationReceiptRepository($this->connection);
            $pending='pending:'.bin2hex(random_bytes(16));
            if(!$receipts->claim($org,self::OPERATION,$key,$pending)){
                $receipt=$receipts->find($org,self::OPERATION,$key);
                if($receipt===null||preg_match('/^([a-f0-9]{64}):([1-9][0-9]{0,18})$/D',$receipt,$m)!==1||!hash_equals($m[1],$hash))throw new DomainException('Lead followup receipt conflict; legacy receipts require reconciliation.');
                $id=$m[2];
                $q=$this->connection->prepare('SELECT a.title,a.body,a.due_at FROM tn_lead_activities a JOIN tn_leads l ON l.id=a.lead_id WHERE l.organization_id=? AND l.id=? AND a.id=? AND a.activity_type="task" FOR UPDATE');
                $q->execute([$org,$lead,$id]);$row=$q->fetch(PDO::FETCH_ASSOC);
                if(!is_array($row)||$row['title']!==$command->title||$row['body']!==$command->body||$row['due_at']!==$due)throw new DomainException('Native lead followup differs.');
                if($owns)$this->connection->commit();
                return OperationResult::success($id,['duplicate'=>true,'due_at'=>$command->dueAt->format(DATE_ATOM)]);
            }
            if(in_array($status,['won','lost','disqualified','spam','closed'],true)||$command->dueAt<=new \DateTimeImmutable())throw new DomainException('Lead is terminal or due date has passed.');
            $q=$this->connection->prepare('INSERT INTO tn_lead_activities(lead_id,user_id,activity_type,title,body,due_at,completed_at) SELECT id,assigned_user_id,"task",?,?,?,NULL FROM tn_leads WHERE organization_id=? AND id=?');
            $q->execute([$command->title,$command->body,$due,$org,$lead]);
            if($q->rowCount()!==1)throw new DomainException('Lead followup not created.');
            $id=(string)$this->connection->lastInsertId();
            $this->connection->prepare('UPDATE tn_leads SET next_contact_at=(SELECT MIN(a.due_at) FROM tn_lead_activities a WHERE a.lead_id=? AND a.activity_type="task" AND a.completed_at IS NULL AND a.cancelled_at IS NULL),updated_at=NOW() WHERE organization_id=? AND id=?')->execute([$lead,$org,$lead]);
            if(!$receipts->complete($org,self::OPERATION,$key,$pending,$hash.':'.$id))throw new \RuntimeException('Lead followup receipt not completed.');
            if($owns)$this->connection->commit();
            return OperationResult::success($id,['duplicate'=>false,'due_at'=>$command->dueAt->format(DATE_ATOM)]);
        }catch(Throwable $e){
            if(!$owns)throw $e;
            if($this->connection->inTransaction())$this->connection->rollBack();
            return OperationResult::failure('Lead followup scheduling rejected.');
        }
    }
}
