<?php
declare(strict_types=1);

namespace Domains\Sales\Application\UseCase;

use Domains\Sales\Application\Contract\ClientCaseCommandRepositoryInterface;
use Domains\Sales\Application\Contract\ClientCaseReadModelInterface;
use Domains\Sales\Application\DTO\ClientCaseCommandResult;
use Domains\Sales\Automation\Event\LeadChanged;
use Kernel\Event\EventBus;
use Kernel\Event\EventMetadata;
use Kernel\Transaction\Contract\TransactionManagerInterface;

final readonly class AttachInboundRequest
{
    public function __construct(private ClientCaseReadModelInterface $readModel,private ClientCaseCommandRepositoryInterface $commands,
        private EventBus $events,private TransactionManagerInterface $transactions,private string $organizationId){}

    public function execute(int $caseId,int $requestId,?array $user=null): ClientCaseCommandResult
    {
        $case=$this->readModel->case($caseId);
        if(!$case) return ClientCaseCommandResult::failure('case_not_found');
        $ok=$this->transactions->transactional(function() use($case,$caseId,$requestId,$user): bool {
            if(!$this->commands->attachInboundRequest($this->organizationId,$caseId,(int)$case['person_id'],$requestId,$user['id']??null)) return false;
            $this->commands->addActivity($this->organizationId,$caseId,(int)$case['person_id'],$user['id']??null,[
                'activity_type'=>'note','title'=>'Заявку привʼязано до кейса','body'=>'Вхідна заявка #'.$requestId.' додана як контекст кейса.',
                'due_at'=>null,'completed_at'=>null,
            ]);
            $eventId=bin2hex(random_bytes(16));
            $this->events->publish(LeadChanged::create($eventId,$this->organizationId,(string)$requestId,
                ['client_case_id'=>['from'=>null,'to'=>$caseId]],new EventMetadata($eventId,null,isset($user['id'])?'USER':'SYSTEM',isset($user['id'])?(string)$user['id']:'system')));
            return true;
        });
        return $ok?ClientCaseCommandResult::success('attached'):ClientCaseCommandResult::failure('request_not_found');
    }
}
