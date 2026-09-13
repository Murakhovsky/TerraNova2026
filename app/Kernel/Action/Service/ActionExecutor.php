<?php
declare(strict_types=1);

namespace Kernel\Action\Service;

use Kernel\Action\Action;
use Kernel\Action\ActionStatus;
use Kernel\Action\Contract\ActionExecutionGateInterface;
use Kernel\Action\Contract\ActionHandlerInterface;
use Kernel\Action\ExecutionResult;
use RuntimeException;

final class ActionExecutor
{
    private readonly ActionHandlerRegistry $handlers;

    /** @param ActionHandlerRegistry|list<ActionHandlerInterface> $handlers */
    public function __construct(
        ActionHandlerRegistry|array $handlers,
        private readonly ActionExecutionGateInterface $executionGate,
    ) {
        $this->handlers = $handlers instanceof ActionHandlerRegistry
            ? $handlers
            : new ActionHandlerRegistry($handlers);
    }

    public function execute(Action $action): ExecutionResult
    {
        $this->executionGate->assertExecutable($action);
        $handler = $this->handlers->handlerFor($action->type);
        if ($action->status === ActionStatus::Queued) {
            $action->transitionTo(ActionStatus::Running);
        } elseif ($action->status !== ActionStatus::Running) {
            throw new RuntimeException(sprintf('Action %s is not executable from %s.', $action->id, $action->status->value));
        }
        $result = $handler->execute($action);
        $action->transitionTo($result->successful ? ActionStatus::Completed : ActionStatus::Failed);
        return $result;
    }
}
