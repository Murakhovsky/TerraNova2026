<?php
declare(strict_types=1);

namespace Kernel\Action;

use Kernel\Execution\ExecutionFailureException;
use Kernel\Execution\ExecutionFailureKind;

final class StaleActionExecutionClaimException extends ExecutionFailureException
{
    public function __construct(ActionExecutionClaim $claim)
    {
        parent::__construct(
            ExecutionFailureKind::ConcurrencyConflict,
            sprintf(
                'Action %s execution claim %d owned by worker %s is no longer current.',
                $claim->action->id,
                $claim->attempt,
                $claim->workerId,
            ),
        );
    }
}
