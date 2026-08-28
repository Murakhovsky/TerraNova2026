<?php
declare(strict_types=1);

namespace Domains\Sales\Application\UseCase;

use Domains\Sales\Application\Contract\ClientCaseCommandRepositoryInterface;
use Domains\Sales\Application\Contract\ClientCaseReadModelInterface;
use Domains\Sales\Application\DTO\ClientCaseCommandResult;
use Domains\Sales\Application\DTO\RecordCompletedCallCommand;
use Kernel\Transaction\Contract\TransactionManagerInterface;

final readonly class AddClientCaseActivity
{
    public function __construct(private ClientCaseReadModelInterface $readModel, private ClientCaseCommandRepositoryInterface $commands,
        private CompleteSalesCall $completeCall, private TransactionManagerInterface $transactions, private string $organizationId) {}

    public function execute(int $caseId,array $input,?array $user=null): ClientCaseCommandResult
    {
        $case=$this->readModel->case($caseId);
        if(!$case) return ClientCaseCommandResult::failure('not_found');
        $completedAt=!empty($input['completed'])?date('Y-m-d H:i:s'):null;
        $type=$this->allowed((string)($input['activity_type']??'note'),['note','call','message','meeting','viewing','offer','status_change','deal','task'],'note');
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
            $this->commands->addActivity($this->organizationId,$caseId,(int)$case['person_id'],$user['id']??null,[
                'activity_type'=>$type,'title'=>mb_substr(trim((string)($input['title']??'Нотатка')),0,180),
                'body'=>$this->nullable((string)($input['body']??'')),'due_at'=>$this->dateTime((string)($input['due_at']??'')),'completed_at'=>$completedAt,
            ]);
            if($completedAt) $this->commands->clearNextContact($this->organizationId,$caseId);
        });
        return ClientCaseCommandResult::success('activity_added');
    }
    private function allowed(string $value,array $allowed,string $default): string{return in_array($value,$allowed,true)?$value:$default;}
    private function nullable(string $value): ?string{$value=trim($value);return $value===''?null:mb_substr($value,0,4000);}
    private function dateTime(string $value): ?string{$timestamp=strtotime(trim($value));return $timestamp?date('Y-m-d H:i:s',$timestamp):null;}
}
