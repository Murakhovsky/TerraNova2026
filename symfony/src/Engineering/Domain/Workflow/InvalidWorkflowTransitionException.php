<?php
declare(strict_types=1);

namespace App\Engineering\Domain\Workflow;

use LogicException;

final class InvalidWorkflowTransitionException extends LogicException
{
    public static function between(EngineeringWorkflowState $from, EngineeringWorkflowState $to): self
    {
        return new self(sprintf('Invalid engineering workflow transition %s -> %s.', $from->value, $to->value));
    }
}
