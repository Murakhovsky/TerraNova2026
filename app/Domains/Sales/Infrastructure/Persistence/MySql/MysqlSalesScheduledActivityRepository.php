<?php
declare(strict_types=1);
namespace Domains\Sales\Infrastructure\Persistence\MySql;

use Domains\Sales\Application\Contract\SalesScheduledActivityRepositoryInterface;
use PDO;
use DomainException;

final readonly class MysqlSalesScheduledActivityRepository implements SalesScheduledActivityRepositoryInterface
{
    public function __construct(private PDO $connection){}
    private function table(string $kind):array
    {
        return match($kind){
            'lead'=>['tn_leads','tn_lead_activities','lead_id','"task","viewing"'],
            'deal'=>['tn_client_cases','tn_client_case_activities','client_case_id','"task","followup","meeting","viewing"'],
            default=>throw new DomainException('Unknown activity target.'),
        };
    }
    public function lockTarget(string $org,string $kind,int $target):?array
    {
        if(!$this->connection->inTransaction())throw new \LogicException('Activity mutation requires a transaction.');
        [$t]=$this->table($kind);
        return $this->one("SELECT id,status,assigned_user_id FROM $t WHERE organization_id=? AND id=? FOR UPDATE",[$org,$target]);
    }
    public function read(string $org,string $kind,int $target,int $activity):?array
    {
        [$t,$a,$fk,$types]=$this->table($kind);
        return $this->one("SELECT a.id,a.activity_type,a.title,a.body,a.due_at,a.completed_at,a.cancelled_at,a.cancellation_reason,COALESCE(a.user_id,t.assigned_user_id) owner_id FROM $a a JOIN $t t ON t.id=a.$fk WHERE t.organization_id=? AND t.id=? AND a.id=? AND a.activity_type IN ($types)".($kind==='deal'?' AND a.organization_id=t.organization_id':'').($this->connection->inTransaction()?' FOR UPDATE':''),[$org,$target,$activity]);
    }
    public function transition(string $org,string $kind,int $target,int $activity,string $operation,?string $due,?string $reason,int $actor):void
    {
        if(!$this->connection->inTransaction())throw new \LogicException('Activity mutation requires a transaction.');
        if($kind==='deal'&&$operation!=='cancel')throw new DomainException('Use existing deal activity lifecycle.');
        [$t,$a,$fk,$types]=$this->table($kind);
        $set=match($operation){
            'cancel'=>'a.cancelled_at=NOW(),a.cancellation_reason=?',
            'complete'=>'a.completed_at=NOW(),a.user_id=COALESCE(a.user_id,?)',
            'reschedule'=>'a.due_at=?',
            default=>throw new DomainException('Unknown activity operation.'),
        };
        $arg=match($operation){'cancel'=>$reason,'complete'=>$actor,default=>$due};
        $q=$this->connection->prepare("UPDATE $a a JOIN $t t ON t.id=a.$fk SET $set WHERE t.organization_id=? AND t.id=? AND a.id=? AND a.activity_type IN ($types) AND a.completed_at IS NULL AND a.cancelled_at IS NULL".($kind==='deal'?' AND a.organization_id=t.organization_id':''));
        $q->execute([$arg,$org,$target,$activity]);
        // Identical rescheduling is a no-op, but only an existing open row can pass.
        if($q->rowCount()!==1&&($operation!=='reschedule'||($this->read($org,$kind,$target,$activity)['due_at']??null)!==$due))throw new DomainException('Open activity mutation rejected.');
        $projection=$kind==='lead'?'"task"':'"followup"';
        $scope=$kind==='deal'?' AND a.organization_id=?':'';
        $args=[$target];if($kind==='deal')$args[]=$org;
        $q=$this->connection->prepare("SELECT MIN(a.due_at) FROM $a a WHERE a.$fk=?$scope AND a.activity_type IN ($projection) AND a.completed_at IS NULL AND a.cancelled_at IS NULL");
        $q->execute($args);$next=$q->fetchColumn();
        $this->connection->prepare("UPDATE $t SET next_contact_at=?,updated_at=NOW() WHERE organization_id=? AND id=?")->execute([$next===false?null:$next,$org,$target]);
    }
    public function overdue(string $org,string $now,int $limit,bool $unannounced=false):array
    {
        $limit=max(1,min(200,$limit));
        $q=$this->connection->prepare('SELECT * FROM (
            SELECT "lead" target_type,l.id target_id,a.id activity_id,a.activity_type,a.due_at,COALESCE(a.user_id,l.assigned_user_id) owner_id
            FROM tn_lead_activities a JOIN tn_leads l ON l.id=a.lead_id
            WHERE l.organization_id=? AND l.status NOT IN ("won","lost","closed","spam","disqualified") AND a.activity_type IN ("task","viewing") AND a.completed_at IS NULL AND a.cancelled_at IS NULL AND a.due_at<=?
            UNION ALL
            SELECT "deal",d.id,a.id,a.activity_type,a.due_at,COALESCE(a.user_id,d.assigned_user_id)
            FROM tn_client_case_activities a JOIN tn_client_cases d ON d.id=a.client_case_id AND d.organization_id=a.organization_id
            WHERE d.organization_id=? AND d.status NOT IN ("closed","lost") AND a.activity_type IN ("task","followup","meeting","viewing") AND a.completed_at IS NULL AND a.cancelled_at IS NULL AND a.due_at<=?
            ) pending '.($unannounced?' WHERE NOT EXISTS (SELECT 1 FROM sales_operation_receipts r WHERE r.organization_id=? AND r.operation_type="activity.reminder.due" AND r.idempotency_key=SHA2(CONCAT(pending.target_type,":",pending.target_id,":",pending.activity_id,":",pending.due_at,":",COALESCE(pending.owner_id,0)),256))':'').' ORDER BY due_at,target_type,activity_id LIMIT '.$limit);
        $args=[$org,$now,$org,$now];if($unannounced)$args[]=$org;
        $q->execute($args);return $q->fetchAll(PDO::FETCH_ASSOC)?:[];
    }
    public function owner(string $org,int $user):?array
    {
        return $this->one('SELECT u.id,u.email,u.full_name FROM tn_users u JOIN cos_organization_memberships m ON m.organization_id=u.organization_id AND m.user_id=u.id WHERE u.organization_id=? AND u.id=? AND u.status="active" AND m.status="ACTIVE"'.($this->connection->inTransaction()?' FOR UPDATE':''),[$org,$user]);
    }
    private function one(string $sql,array $args):?array
    {
        $q=$this->connection->prepare($sql);$q->execute($args);$row=$q->fetch(PDO::FETCH_ASSOC);return is_array($row)?$row:null;
    }
}
