<?php
declare(strict_types=1);

namespace Domains\Sales\Application\UseCase;

use Domains\Sales\Application\Contract\ClientCaseCommandRepositoryInterface;
use Domains\Sales\Application\Contract\ClientCaseReadModelInterface;
use Domains\Sales\Application\DTO\ClientCaseCommandResult;
use Domains\Sales\Application\DTO\RecordCompletedCallCommand;
use Domains\Sales\Model\SalesActivityType;
use Kernel\Transaction\Contract\TransactionManagerInterface;
use Kernel\Event\DomainEvent;
use Kernel\Event\EventBus;
use Kernel\Event\EventMetadata;
use Domains\Sales\Automation\Event\SalesEventType;

final readonly class AddClientCaseActivity
{
    public function __construct(private ClientCaseReadModelInterface $readModel, private ClientCaseCommandRepositoryInterface $commands,
        private CompleteSalesCall $completeCall, private TransactionManagerInterface $transactions, private string $organizationId,
        private ?EventBus $events = null) {}

    public function execute(int $caseId,array $input,?array $user=null): ClientCaseCommandResult
    {
        $case=$this->readModel->case($caseId);
        if(!$case) return ClientCaseCommandResult::failure('not_found');
        $completedAt=!empty($input['completed'])?date('Y-m-d H:i:s'):null;
        $type=$this->allowed((string)($input['activity_type']??'note'),SalesActivityType::values(),'note');
        if($type==='call' && $completedAt!==null){
            $eventId=bin2hex(random_bytes(16));
            $result=trim(mb_substr((string)($input['call_result']??$input['result']??''),0,100));
            $this->completeCall->execute(new RecordCompletedCallCommand($this->organizationId,(string)$caseId,(string)$case['person_id'],
                isset($user['id'])?(string)$user['id']:null,mb_substr(trim((string)($input['title']??'Дзвінок')),0,180),
                $this->nullable((string)($input['body']??'')),max(0,(int)($input['duration_seconds']??$input['duration']??0)),
                $result!==''?$result:'completed',$eventId,$eventId,isset($user['id'])?'USER':'SYSTEM',isset($user['id'])?(string)$user['id']:'system'));
            return ClientCaseCommandResult::success('activity_added');
        }
        $this->transactions->transactional(function() use($case,$caseId,$input,$user,$completedAt,$type): void {
            $activityId=$this->commands->addActivity($this->organizationId,$caseId,(int)$case['person_id'],$user['id']??null,[
                'activity_type'=>$type,'title'=>mb_substr(trim((string)($input['title']??'Нотатка')),0,180),
                'body'=>$this->nullable((string)($input['body']??'')),'due_at'=>$this->dateTime((string)($input['due_at']??'')),'completed_at'=>$completedAt,
            ]);
            if($completedAt) $this->commands->clearNextContact($this->organizationId,$caseId);
            $eventType = match (true) {
                $type === 'meeting' && $completedAt !== null => SalesEventType::MEETING_COMPLETED,
                $type === 'task' && $completedAt !== null => SalesEventType::TASK_COMPLETED,
                $type === 'task' => SalesEventType::TASK_CREATED,
                $type === 'followup' && $completedAt !== null => SalesEventType::FOLLOWUP_COMPLETED,
                $type === 'followup' => SalesEventType::FOLLOWUP_CREATED,
                default => null,
            };
            if ($eventType !== null && $this->events !== null) {
                $eventId=bin2hex(random_bytes(16));
                $this->events->publish(new DomainEvent($eventId,$this->organizationId,$eventType,'deal',(string)$caseId,
                    ['activity_id'=>(string)$activityId,'person_id'=>(string)$case['person_id']],
                    new EventMetadata($eventId,null,isset($user['id'])?'USER':'SYSTEM',isset($user['id'])?(string)$user['id']:'system'),new \DateTimeImmutable()));
            }
        });
        return ClientCaseCommandResult::success('activity_added');
    }
    private function allowed(string $value,array $allowed,string $default): string{return in_array($value,$allowed,true)?$value:$default;}
    private function nullable(string $value): ?string{$value=trim($value);return $value===''?null:mb_substr($value,0,4000);}
    private function dateTime(string $value): ?string{$timestamp=strtotime(trim($value));return $timestamp?date('Y-m-d H:i:s',$timestamp):null;}
}
