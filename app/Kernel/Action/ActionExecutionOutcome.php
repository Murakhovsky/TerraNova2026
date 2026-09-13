<?php
declare(strict_types=1);

namespace Kernel\Action;

final readonly class ActionExecutionOutcome
{
    public function __construct(
        public ActionExecutionClaim $claim,
        public ExecutionResult $result,
    ) {}

    public function action(): Action
    {
        return $this->claim->action;
    }
}
