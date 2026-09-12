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
    /** @var array<string, ActionHandlerInterface> */
    private array $handlerCache = [];

    /** @param list<ActionHandlerInterface> $handlers */
    public function __construct(
        private readonly array $handlers,
        private readonly ActionExecutionGateInterface $executionGate,
    ) {
    }

    public function execute(Action $action): ExecutionResult
    {
        $this->executionGate->assertExecutable($action);
        $handler = $this->handlerFor($action->type);
        if ($action->status === ActionStatus::Queued) {
            $action->transitionTo(ActionStatus::Running);
        } elseif ($action->status !== ActionStatus::Running) {
            throw new RuntimeException(sprintf('Action %s is not executable from %s.', $action->id, $action->status->value));
        }
        $result = $handler->execute($action);
        $action->transitionTo($result->successful ? ActionStatus::Completed : ActionStatus::Failed);
        return $result;
    }

    private function handlerFor(string $type): ActionHandlerInterface
    {
        if (isset($this->handlerCache[$type])) {
            return $this->handlerCache[$type];
        }

        foreach ($this->handlers as $handler) {
            if ($handler->supports($type)) {
                return $this->handlerCache[$type] = $handler;
            }
        }

        throw new RuntimeException(sprintf('No action handler registered for %s.', $type));
    }
}
