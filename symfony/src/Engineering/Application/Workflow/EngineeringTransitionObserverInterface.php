<?php
declare(strict_types=1);

namespace App\Engineering\Application\Workflow;

use App\Engineering\Domain\Workflow\WorkflowTransition;

interface EngineeringTransitionObserverInterface
{
    public function afterPersisted(WorkflowTransition $transition): void;
}
