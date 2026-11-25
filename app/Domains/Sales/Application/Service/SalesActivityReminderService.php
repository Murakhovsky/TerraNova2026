<?php
declare(strict_types=1);
namespace Domains\Sales\Application\Service;
use DateTimeImmutable;
use DomainException;
use Domains\Sales\Application\Contract\SalesScheduledActivityRepositoryInterface;
use Domains\Sales\Application\Contract\SalesMutationReceiptRepositoryInterface;
use Kernel\Event\EventBus;
use Kernel\Event\DomainEvent;
use Kernel\Event\EventMetadata;
use Kernel\Module\ActiveModuleResolver;
use Kernel\Transaction\Contract\TransactionManagerInterface;
use Kernel\Action\Action;
use Kernel\Action\ActionStatus;
use Kernel\Shared\Domain\OrganizationId;
use Platform\Notification\Contract\NotificationDispatcherInterface;
use Platform\Notification\Contract\NotificationHandoffReadPort;
use Platform\Notification\Model\Notification;
use Platform\Notification\Model\Channel;
use Platform\Notification\Model\Recipient;

final readonly class SalesActivityReminderService
{
    public function __construct(private SalesScheduledActivityRepositoryInterface $activities,private SalesMutationReceiptRepositoryInterface $receipts,
        private EventBus $events,private TransactionManagerInterface $transactions,private ActiveModuleResolver $modules,
        private NotificationDispatcherInterface $notifications,private NotificationHandoffReadPort $handoff){}
    /** Existing scheduled scan produces durable, tenant-scoped reminders; never sends external mail automatically. */
    public function scan(string $org,DateTimeImmutable $now,int $limit):int
    {
        if(!$this->modules->isEnabled($org,'sales'))return 0;
        $count=0;$at=$now->format('Y-m-d H:i:s');
        foreach($this->activities->overdue($org,$at,$limit,true) as $candidate){
            $count+=(int)$this->transactions->transactional(function()use($org,$now,$at,$candidate):bool{
                $kind=$candidate['target_type'];$target=(int)$candidate['target_id'];$id=(int)$candidate['activity_id'];
                $root=$this->activities->lockTarget($org,$kind,$target);$row=$this->activities->read($org,$kind,$target,$id);
                if(!$this->eligible($root,$row,$at)||$row['due_at']!==$candidate['due_at']||(int)$row['owner_id']!==(int)$candidate['owner_id'])return false;
                $key=$this->identity($kind,$target,$id,$row['due_at'],(int)$row['owner_id']);
                $event=bin2hex(random_bytes(16));
                if(!$this->receipts->claim($org,'activity.reminder.due',$key,$event))return false;
                $this->events->publish(new DomainEvent($event,$org,'sales.activity.reminder.due',$kind,(string)$target,
                    ['activity_id'=>$id,'activity_type'=>$row['activity_type'],'due_at'=>$row['due_at'],'owner_id'=>$row['owner_id']===null?null:(int)$row['owner_id']],
                    new EventMetadata($event,null,'SYSTEM','sales-activity-reminder'),$now));
                return true;
            });
        }
        return $count;
    }
    /** Explicit approved owner-email handoff, independently read from the Platform transport. */
    public function queue(Action $action):array
    {
        $org=$action->organizationId;$kind=$action->targetType;$target=$action->targetId;$in=$action->parameters;
        if(!$this->modules->isEnabled($org,'sales')||$action->status!==ActionStatus::Running||$action->executionMode!=='APPROVAL_REQUIRED'||$action->idempotencyKey===null||trim($action->idempotencyKey)===''||!in_array($kind,['lead','deal'],true)||$target===null||!ctype_digit($target)||(string)(int)$target!==$target||(int)$target<1)throw new DomainException('Approved reminder action required.');
        if(array_diff(array_keys($in),['activity_id','expected_due_at','expected_owner_id'])!==[]||!is_int($in['activity_id']??null)||$in['activity_id']<1||!is_int($in['expected_owner_id']??null)||$in['expected_owner_id']<1||!is_string($in['expected_due_at']??null)||preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/D',$in['expected_due_at'])!==1)throw new DomainException('Invalid reminder specification.');
        return $this->transactions->transactional(function()use($org,$kind,$target,$in):array{
            $id=$in['activity_id'];$root=$this->activities->lockTarget($org,$kind,(int)$target);
            $row=$this->activities->read($org,$kind,(int)$target,$id);
            if(!$this->eligible($root,$row,date('Y-m-d H:i:s'))||$row['due_at']!==$in['expected_due_at']||(int)$row['owner_id']!==$in['expected_owner_id'])throw new DomainException('Reminder target no longer due or owner changed.');
            $owner=$this->activities->owner($org,$in['expected_owner_id']);
            if($owner===null||filter_var($owner['email']??'',FILTER_VALIDATE_EMAIL)===false)throw new DomainException('Active owner email unavailable.');
            $notification='SREM-'.substr(hash('sha256',$org.':'.$this->identity($kind,(int)$target,$id,$row['due_at'],$in['expected_owner_id'])),0,40);
            $native=$this->handoff->status($org,$notification);
            if($native===null){
                $this->notifications->send(new Notification($notification,OrganizationId::fromString($org),Channel::EMAIL,'sales.activity.reminder',
                    new Recipient($owner['email'],$owner['full_name']),['subject'=>'Sales activity overdue','body'=>'Activity #'.$id.' for '.$kind.' #'.$target.' was due '.$row['due_at'].'. Review it in COS Sales.'],
                    'en',$notification,new DateTimeImmutable(),['target_type'=>$kind,'target_id'=>(int)$target,'activity_id'=>$id,'owner_id'=>$in['expected_owner_id']]));
                $native=$this->handoff->status($org,$notification);
            }
            if($native===null||!in_array($native['state'],['queued','processing','handed_off'],true))throw new DomainException('Reminder transport needs reconciliation.');
            return ['notification_id'=>$notification,'state'=>$native['state'],'recipient_delivery_verified'=>false,'activity_id'=>$id];
        });
    }
    private function eligible(?array $root,?array $row,string $now):bool
    {
        return $root!==null&&!in_array($root['status'],['won','lost','closed','spam','disqualified'],true)&&$row!==null&&$row['completed_at']===null&&$row['cancelled_at']===null&&$row['due_at']!==null&&$row['due_at']<=$now;
    }
    private function identity(string $kind,int $target,int $id,string $due,int $owner):string{return hash('sha256',implode(':',[$kind,$target,$id,$due,$owner]));}
}
