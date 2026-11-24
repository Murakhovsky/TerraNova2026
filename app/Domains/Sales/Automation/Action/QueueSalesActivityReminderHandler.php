<?php
declare(strict_types=1);
namespace Domains\Sales\Automation\Action;
use Domains\Sales\Application\Service\SalesActivityReminderService;
use Kernel\Action\Action;
use Kernel\Action\Contract\IdempotentExternalActionHandlerInterface;
use Kernel\Action\ExecutionResult;
use Kernel\Action\ExternalActionIdempotency;
final readonly class QueueSalesActivityReminderHandler implements IdempotentExternalActionHandlerInterface
{
    public const TYPE='sales.activity.reminder.queue';
    public function __construct(private SalesActivityReminderService $reminders){}
    public function supports(string $actionType):bool{return $actionType===self::TYPE;}
    public function idempotencyKey(Action $action):string{return ExternalActionIdempotency::resolve($action);}
    public function execute(Action $action):ExecutionResult
    {
        if(!$this->supports($action->type))return ExecutionResult::failure('Unsupported reminder action.');
        try{return ExecutionResult::success($this->reminders->queue($action),['reminder_queue_evidence'=>1]);}
        catch(\DomainException $e){return ExecutionResult::failure($e->getMessage());}
    }
}
