<?php
declare(strict_types=1);
namespace App\Application\Sales\Command;

use DateTimeImmutable;
use DateTimeZone;
use DomainException;
use Domains\Sales\Application\Contract\DealStageRepositoryInterface;
use Domains\Sales\Application\Contract\SalesActivityEvidenceReadPort;
use Domains\Sales\Application\Contract\SalesMutationReceiptRepositoryInterface;
use Domains\Sales\Application\Contract\SalesWriteServiceFactoryInterface;
use Domains\Sales\Application\Service\SalesOperationService;
use Domains\Sales\Domain\Policy\PropertyMatchLifecycle;
use Domains\Property\Contract\PropertyReferencePort;
use Kernel\Application\Command\CommandHandlerInterface;
use Kernel\Transaction\Contract\TransactionManagerInterface;
use RuntimeException;

final readonly class ManageSalesActivityCommandHandler implements CommandHandlerInterface
{
    public function __construct(private SalesWriteServiceFactoryInterface $writes,private SalesOperationService $operations,
        private DealStageRepositoryInterface $deals,private SalesActivityEvidenceReadPort $evidence,
        private SalesMutationReceiptRepositoryInterface $receipts,private PropertyReferencePort $properties,
        private TransactionManagerInterface $transactions){}

    public function __invoke(ManageSalesActivityCommand $command):array
    {
        $org=$command->organizationId->value();$input=$command->input;$op=$command->operation;
        $id=$input['deal_id']??null;$key=trim($command->idempotencyKey);
        if (!is_int($id)||$id<1||$command->actorId<1||$key===''||strlen($key)>191) throw new DomainException('Invalid activity identity.');
        $this->validate($op,$input);ksort($input);
        $hash=hash('sha256',json_encode([$command->actorId,$op,$input],JSON_THROW_ON_ERROR));$namespace='operator.'.$op;
        return $this->transactions->transactional(function()use($command,$org,$id,$input,$op,$key,$hash,$namespace):array{
            $deal=$this->deals->getForStageChange($org,(string)$id);
            if ($deal===null||($deal['organization_id']??null)!==$org||(int)$deal['id']!==$id) throw new DomainException('Tenant-owned deal not found.');
            $pending='pending:'.bin2hex(random_bytes(16));
            if (!$this->receipts->claim($org,$namespace,$key,$pending)){
                $receipt=$this->receipts->find($org,$namespace,$key);
                if ($receipt===null||preg_match('/^([a-f0-9]{64}):([1-9][0-9]{0,18})$/D',$receipt,$parts)!==1||!hash_equals($hash,$parts[1])) throw new DomainException('Activity receipt conflict.');
                return $this->readback($org,$id,(int)$parts[2],$op,$input,true);
            }
            if (in_array($deal['status'],['closed','lost'],true)&&$op!=='activity.complete'&&!($op==='property.match'&&$deal['status']==='closed'&&($input['match_status']??null)==='deal')) throw new DomainException('Terminal deal cannot receive new operational activity.');
            $write=$this->writes->forOrganization($org);
            if ($op==='activity.record'){
                if (isset($input['due_at'])&&$this->date($input['due_at'])<=new DateTimeImmutable()) throw new DomainException('Activity must be scheduled in the future.');
                if(isset($input['due_at']))$input['due_at']=$this->date($input['due_at'])->format(DATE_ATOM);
                $result=$write->addOpportunityActivity($id,array_diff_key($input,['deal_id'=>true]),$command->actorId,$command->correlationId);
                if (!$result->ok) throw new DomainException($result->code);
                $nativeId=(int)($result->data['activity_id']??0);
            }elseif($op==='followup.schedule'){
                $result=$write->scheduleNextAction($id,$input['title'],$input['body']??null,$this->date($input['due_at']),$command->actorId,$command->correlationId,hash('sha256','operator:'.$key));
                if (!$result->successful) throw new DomainException($result->error??'Followup rejected.');
                $nativeId=(int)$result->externalId;
            }elseif($op==='activity.complete'||$op==='activity.reschedule'){
                $nativeId=$input['activity_id'];$before=$this->evidence->activity($org,$id,$nativeId);
                if ($before===null||$before['completed_at']!==null||$before['cancelled_at']!==null||!in_array($before['activity_type'],['task','followup','meeting','viewing'],true)) throw new DomainException('Open scheduled activity required.');
                $result=$op==='activity.complete'?$this->operations->completeActivity($org,(string)$id,$nativeId,$command->actorId,'USER',$command->correlationId)
                    :$this->operations->rescheduleActivity($org,(string)$id,$nativeId,$this->date($input['due_at']));
                if (!$result->successful) throw new DomainException($result->error??'Activity mutation rejected.');
            }else{
                $property=$input['property_id'];
                $reference=$this->properties->getPropertyReference($org,$property);
                if ($reference===null) throw new DomainException('Tenant-owned Property reference required.');
                $before=$this->evidence->propertyMatch($org,$id,$property);
                $from=$before['match_status']??null;$to=$input['match_status'];
                if ($from!==($input['expected_status']??null)||!PropertyMatchLifecycle::allows($from,$to)) throw new DomainException('Stale or prohibited property match transition.');
                // A deal match records a commercial outcome, never writes Property inventory.
                if ($to==='deal'&&$deal['status']!=='closed') throw new DomainException('Deal match requires independently recorded outcome.');
                $changes=array_intersect_key($input,['match_status'=>true,'score'=>true,'note'=>true]);
                if ($before===null) $result=$write->addOpportunityPropertyMatch($id,$property,$changes,$command->actorId);
                else $result=$write->updateOpportunityPropertyMatch((int)$before['id'],$changes,$command->actorId);
                if (!$result->ok) throw new DomainException($result->code);
                $nativeId=(int)($this->evidence->propertyMatch($org,$id,$property)['id']??0);
            }
            if ($nativeId<1) throw new RuntimeException('Native activity identity missing.');
            $out=$this->readback($org,$id,$nativeId,$op,$input,false);
            if (!$this->receipts->complete($org,$namespace,$key,$pending,$hash.':'.$nativeId)) throw new RuntimeException('Activity receipt completion failed.');
            return $out;
        });
    }

    private function readback(string $org,int $deal,int $id,string $op,array $input,bool $replayed):array
    {
        if ($op==='property.match'){
            $row=$this->evidence->propertyMatch($org,$deal,$input['property_id']);
            if ($row===null||(int)$row['id']!==$id||$row['match_status']!==$input['match_status']
                ||(array_key_exists('score',$input)&&(int)$row['score']!==$input['score'])
                ||(array_key_exists('note',$input)&&($row['note']??'')!==$input['note'])) throw new DomainException('Property match native state differs.');
            return ['deal_id'=>$deal,'record_id'=>$id,'kind'=>'property_match','status'=>$row['match_status'],'replayed'=>$replayed];
        }
        $row=$this->evidence->activity($org,$deal,$id);
        if ($row===null) throw new DomainException('Native activity not found.');
        if ($op==='activity.record'&&($row['activity_type']!==$input['activity_type']||$row['title']!==$input['title']||($row['body']??'')!==($input['body']??'')
            ||(!empty($input['completed'])&&$row['completed_at']===null))) throw new DomainException('Recorded activity differs.');
        if ($op==='activity.record'&&isset($input['due_at'])&&$row['due_at']!==$this->date($input['due_at'])->format('Y-m-d H:i:s')) throw new DomainException('Recorded activity due date differs.');
        if ($op==='followup.schedule'&&($row['activity_type']!=='followup'||$row['title']!==$input['title']||($row['body']??'')!==($input['body']??''))) throw new DomainException('Followup differs.');
        if (in_array($op,['followup.schedule','activity.reschedule'],true)&&$row['due_at']!==$this->date($input['due_at'])->format('Y-m-d H:i:s')) throw new DomainException('Native due date changed.');
        if ($op==='activity.complete'&&$row['completed_at']===null) throw new DomainException('Activity is not completed.');
        return ['deal_id'=>$deal,'record_id'=>$id,'kind'=>$row['activity_type'],'status'=>$row['cancelled_at']!==null?'cancelled':($row['completed_at']===null?'open':'completed'),'replayed'=>$replayed];
    }

    private function validate(string $op,array $in):void
    {
        $allowed=match($op){
            'activity.record'=>['activity_type','title','body','completed','due_at','call_result','duration_seconds'],
            'followup.schedule'=>['title','body','due_at'],
            'activity.complete'=>['activity_id'],
            'activity.reschedule'=>['activity_id','due_at'],
            'property.match'=>['property_id','expected_status','match_status','score','note'],
            default=>throw new DomainException('Unknown operation.'),
        };
        if (array_diff(array_keys($in),['deal_id',...$allowed])!==[]) throw new DomainException('Unknown activity fields.');
        foreach(['title'=>180,'body'=>4000,'note'=>500,'call_result'=>100] as $field=>$limit)if(array_key_exists($field,$in)&&(!is_string($in[$field])||trim($in[$field])!==$in[$field]||mb_strlen($in[$field])>$limit))throw new DomainException('Invalid activity text.');
        if (in_array($op,['activity.record','followup.schedule'],true)&&(!is_string($in['title']??null)||$in['title']==='')) throw new DomainException('Activity title required.');
        if ($op==='activity.record'){
            $type=$in['activity_type']??null;$completed=$in['completed']??null;
            if (!in_array($type,['note','call','meeting','viewing','task'],true)||!is_bool($completed)) throw new DomainException('Invalid activity type or state.');
            if ($type==='call'&&(!$completed||!is_int($in['duration_seconds']??null)||$in['duration_seconds']<0||$in['duration_seconds']>86400||empty($in['call_result']))) throw new DomainException('Completed call evidence required.');
            if ($type!=='call'&&(isset($in['call_result'])||isset($in['duration_seconds']))) throw new DomainException('Unexpected call evidence.');
            if (!$completed&&in_array($type,['task','meeting','viewing'],true)&&!isset($in['due_at'])) throw new DomainException('Scheduled activity requires due_at.');
            if ($completed&&isset($in['due_at'])) throw new DomainException('Completed activity cannot be scheduled.');
            if ($type==='note'&&!$completed) throw new DomainException('Note is an already recorded observation.');
        }
        if (in_array($op,['activity.complete','activity.reschedule'],true)&&(!is_int($in['activity_id']??null)||$in['activity_id']<1)) throw new DomainException('Activity id required.');
        if (in_array($op,['activity.reschedule','followup.schedule'],true)||isset($in['due_at'])) $this->date($in['due_at']??null);
        if ($op==='property.match'&&(!is_int($in['property_id']??null)||$in['property_id']<1||!is_string($in['match_status']??null)
            ||(isset($in['expected_status'])&&!is_string($in['expected_status']))||!PropertyMatchLifecycle::allows($in['expected_status']??null,$in['match_status'])
            ||(isset($in['score'])&&(!is_int($in['score'])||$in['score']<0||$in['score']>100)))) throw new DomainException('Invalid property match fields.');
    }

    private function date(mixed $raw):DateTimeImmutable
    {
        if (!is_string($raw)||preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(Z|[+-]\d{2}:\d{2})$/D',$raw)!==1) throw new DomainException('Explicit RFC3339 due date required.');
        $value=new DateTimeImmutable($raw);$errors=DateTimeImmutable::getLastErrors();
        if ($errors!==false&&($errors['warning_count']||$errors['error_count'])) throw new DomainException('Invalid due date.');
        return $value->setTimezone(new DateTimeZone(date_default_timezone_get()));
    }
}
