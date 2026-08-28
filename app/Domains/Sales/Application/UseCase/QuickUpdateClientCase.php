<?php
declare(strict_types=1);

namespace Domains\Sales\Application\UseCase;

use Domains\Sales\Application\Contract\ClientCaseCommandRepositoryInterface;
use Domains\Sales\Application\Contract\ClientCaseReadModelInterface;
use Domains\Sales\Application\DTO\ClientCaseCommandResult;
use Domains\Sales\Automation\Event\ClientCaseChanged;
use Domains\Sales\Automation\Event\DealStageChanged;
use Kernel\Event\EventBus;
use Kernel\Event\EventMetadata;
use Kernel\Transaction\Contract\TransactionManagerInterface;

final readonly class QuickUpdateClientCase
{
    public function __construct(private ClientCaseReadModelInterface $readModel, private ClientCaseCommandRepositoryInterface $commands,
        private EventBus $events, private TransactionManagerInterface $transactions, private string $organizationId) {}

    public function execute(int $caseId, array $input, ?array $user = null): ClientCaseCommandResult
    {
        $case = $this->readModel->case($caseId);
        if (!$case) return ClientCaseCommandResult::failure('not_found');
        $status = $this->allowed((string)($input['status']??$case['status']), ['active','paused','closed','lost'], (string)$case['status']);
        $stages = ['new','qualification','need_defined','matching','viewing','negotiation','deal','aftercare','repeat','paused','lost'];
        $stage = $this->allowed((string)($input['stage']??$case['stage']), $stages, (string)$case['stage']);
        $priority = $this->allowed((string)($input['priority']??$case['priority']), ['low','normal','high','urgent'], (string)$case['priority']);
        $managerId = array_key_exists('assigned_user_id',$input)
            ? $this->commands->activeManagerId($this->organizationId,$input['assigned_user_id']) : ($case['assigned_user_id']??null);
        $nextContact = array_key_exists('next_contact_at',$input) ? $this->dateTime((string)($input['next_contact_at']??'')) : ($case['next_contact_at']??null);
        $correlationId = bin2hex(random_bytes(16));

        $ok = $this->transactions->transactional(function() use($case,$caseId,$status,$stage,$priority,$managerId,$nextContact,$user,$correlationId): bool {
            $updated = $this->commands->quickUpdate($this->organizationId,$caseId,[
                'status'=>$status,'stage'=>$stage,'priority'=>$priority,'assigned_user_id'=>$managerId,'next_contact_at'=>$nextContact,
                'closed_at'=>in_array($status,['closed','lost'],true)?(($case['closed_at']??null)?:date('Y-m-d H:i:s')):null,
            ]);
            if (!$updated) return false;
            $this->commands->addActivity($this->organizationId,$caseId,(int)$case['person_id'],$user['id']??null,[
                'activity_type'=>'status_change','title'=>'Кейс швидко оновлено',
                'body'=>'Оновлено етап, статус, пріоритет або відповідального менеджера.','due_at'=>null,'completed_at'=>null,
            ]);
            $metadata = new EventMetadata($correlationId,null,isset($user['id'])?'USER':'SYSTEM',isset($user['id'])?(string)$user['id']:'system');
            $this->events->publish(ClientCaseChanged::create(bin2hex(random_bytes(16)),$this->organizationId,(string)$caseId,[
                'stage'=>['from'=>(string)$case['stage'],'to'=>$stage],'status'=>['from'=>(string)$case['status'],'to'=>$status],
                'priority'=>['from'=>(string)$case['priority'],'to'=>$priority],
            ],$metadata));
            if ((string)$case['stage'] !== $stage) $this->events->publish(DealStageChanged::create(
                bin2hex(random_bytes(16)),$this->organizationId,(string)$caseId,(string)$case['stage'],$stage,$metadata));
            return true;
        });
        return $ok ? ClientCaseCommandResult::success('updated') : ClientCaseCommandResult::failure('not_found');
    }

    private function allowed(string $value,array $allowed,string $default): string { return in_array($value,$allowed,true)?$value:$default; }
    private function dateTime(string $value): ?string { $timestamp=strtotime(trim($value)); return $timestamp?date('Y-m-d H:i:s',$timestamp):null; }
}
