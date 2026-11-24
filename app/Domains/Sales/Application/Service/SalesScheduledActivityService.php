<?php
declare(strict_types=1);
namespace Domains\Sales\Application\Service;

use DateTimeImmutable;
use DateTimeZone;
use DomainException;
use Domains\Sales\Application\Contract\SalesScheduledActivityRepositoryInterface;
use Domains\Sales\Application\Contract\SalesMutationReceiptRepositoryInterface;
use Domains\Sales\Application\DTO\ScheduleLeadFollowupCommand;
use Domains\Sales\Application\UseCase\ScheduleLeadFollowup;
use Kernel\Event\DomainEvent;
use Kernel\Event\EventBus;
use Kernel\Event\EventMetadata;
use Kernel\Transaction\Contract\TransactionManagerInterface;

final readonly class SalesScheduledActivityService
{
    public function __construct(private SalesScheduledActivityRepositoryInterface $activities,private ScheduleLeadFollowup $schedule,
        private SalesMutationReceiptRepositoryInterface $receipts,private EventBus $events,private TransactionManagerInterface $transactions){}
    public function execute(string $org,int $actor,string $operation,array $input,string $key,string $trace):array
    {
        $kind=$operation==='deal.cancel'?'deal':'lead';
        $target=$input[$kind.'_id']??null;$id=$input['activity_id']??null;
        if($actor<1||!is_int($target)||$target<1||trim($key)===''||strlen($key)>191||trim($trace)==='')throw new DomainException('Invalid activity command.');
        $allowed=match($operation){
            'lead.schedule'=>['lead_id','title','body','due_at'],
            'lead.complete'=>['lead_id','activity_id'],
            'lead.reschedule'=>['lead_id','activity_id','due_at'],
            'lead.cancel'=>['lead_id','activity_id','reason'],
            'deal.cancel'=>['deal_id','activity_id','reason'],
            default=>throw new DomainException('Unsupported lifecycle operation.'),
        };
        if(array_diff(array_keys($input),$allowed)!==[]||($operation!=='lead.schedule'&&(!is_int($id)||$id<1)))throw new DomainException('Invalid activity fields.');
        $date=null;
        if(in_array($operation,['lead.schedule','lead.reschedule'],true)){
            $raw=$input['due_at']??null;
            if(!is_string($raw)||preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(Z|[+-]\d{2}:\d{2})$/D',$raw)!==1)throw new DomainException('RFC3339 due_at required.');
            $date=new DateTimeImmutable($raw);$errors=DateTimeImmutable::getLastErrors();
            if($errors!==false&&($errors['warning_count']||$errors['error_count']))throw new DomainException('Invalid due date.');
            $date=$date->setTimezone(new DateTimeZone(date_default_timezone_get()));
        }
        if($operation==='lead.schedule'&&(!is_string($input['title']??null)||trim($input['title'])!==$input['title']||$input['title']===''||mb_strlen($input['title'])>180||(isset($input['body'])&&(!is_string($input['body'])||mb_strlen($input['body'])>4000))))throw new DomainException('Invalid followup title/body.');
        $reason=$input['reason']??null;
        if(str_ends_with($operation,'.cancel')&&(!is_string($reason)||trim($reason)!==$reason||$reason===''||mb_strlen($reason)>500))throw new DomainException('Cancellation reason required.');
        ksort($input);$hash=hash('sha256',json_encode([$actor,$operation,$input],JSON_THROW_ON_ERROR));
        $namespace='operator.'.$operation;
        return $this->transactions->transactional(function()use($org,$actor,$operation,$kind,$target,$id,$input,$key,$trace,$date,$reason,$hash,$namespace):array{
            $targetRow=$this->activities->lockTarget($org,$kind,$target);
            if($targetRow===null)throw new DomainException('Tenant-owned target not found.');
            $pending='pending:'.bin2hex(random_bytes(16));
            if(!$this->receipts->claim($org,$namespace,$key,$pending)){
                $receipt=$this->receipts->find($org,$namespace,$key);
                if($receipt===null||preg_match('/^([a-f0-9]{64}):([1-9][0-9]{0,18})$/D',$receipt,$m)!==1||!hash_equals($m[1],$hash))throw new DomainException('Activity receipt conflict.');
                return $this->readback($org,$kind,$target,(int)$m[2],$operation,$date,$reason,true);
            }
            if($date!==null&&$date<=new DateTimeImmutable())throw new DomainException('Due date must be in the future.');
            if(in_array($targetRow['status'],['won','lost','closed','spam','disqualified'],true)&&in_array($operation,['lead.schedule','lead.reschedule'],true))throw new DomainException('Terminal target cannot receive followup.');
            if($operation==='lead.schedule'){
                $result=$this->schedule->execute(new ScheduleLeadFollowupCommand($org,(string)$target,$input['title'],$input['body']??null,$date,hash('sha256','operator:'.$key)),$trace,'USER',(string)$actor);
                if(!$result->successful)throw new DomainException('Lead followup rejected.');
                $id=(int)$result->externalId;
            }else{
                $before=$this->activities->read($org,$kind,$target,$id);
                if($before===null||$before['completed_at']!==null||$before['cancelled_at']!==null)throw new DomainException('Open activity required.');
                $verb=substr($operation,strpos($operation,'.')+1);
                $this->activities->transition($org,$kind,$target,$id,$verb,$date?->format('Y-m-d H:i:s'),$reason,$actor);
                $type=match($verb){'cancel'=>'sales.activity.cancelled','complete'=>'sales.activity.completed',default=>'sales.activity.rescheduled'};
                $this->events->publish(new DomainEvent(bin2hex(random_bytes(16)),$org,$type,$kind,(string)$target,
                    ['activity_id'=>$id,'activity_type'=>$before['activity_type'],'due_at'=>$date?->format(DATE_ATOM),'reason'=>$reason],
                    new EventMetadata($trace,null,'USER',(string)$actor),new DateTimeImmutable()));
            }
            $out=$this->readback($org,$kind,$target,$id,$operation,$date,$reason,false);
            if(!$this->receipts->complete($org,$namespace,$key,$pending,$hash.':'.$id))throw new \RuntimeException('Lifecycle receipt not completed.');
            return $out;
        });
    }
    private function readback(string $org,string $kind,int $target,int $id,string $op,?DateTimeImmutable $date,?string $reason,bool $replayed):array
    {
        $row=$this->activities->read($org,$kind,$target,$id);
        if($row===null||($date!==null&&$row['due_at']!==$date->format('Y-m-d H:i:s'))||(str_ends_with($op,'.cancel')&&($row['cancelled_at']===null||$row['completed_at']!==null||$row['cancellation_reason']!==$reason))||(str_ends_with($op,'.complete')&&$row['completed_at']===null))throw new DomainException('Activity native readback differs.');
        return ['target_type'=>$kind,'target_id'=>$target,'activity_id'=>$id,'status'=>$row['cancelled_at']!==null?'cancelled':($row['completed_at']!==null?'completed':'open'),'replayed'=>$replayed];
    }
}
