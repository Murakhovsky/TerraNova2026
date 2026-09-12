<?php
declare(strict_types=1);

namespace Kernel\Action;

use RuntimeException;

final class StaleActionExecutionClaimException extends RuntimeException
{
    public function __construct(ActionExecutionClaim $claim)
    {
        parent::__construct(sprintf(
            'Action %s execution claim %d owned by worker %s is no longer current.',
            $claim->action->id,
            $claim->attempt,
            $claim->workerId,
        ));
    }
}
