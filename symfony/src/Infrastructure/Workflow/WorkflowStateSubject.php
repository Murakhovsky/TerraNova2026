<?php
declare(strict_types=1);

namespace App\Infrastructure\Workflow;

use Kernel\Workflow\Model\WorkflowStatus;

final class WorkflowStateSubject
{
    public function __construct(private WorkflowStatus $state) {}

    public function getState(): WorkflowStatus
    {
        return $this->state;
    }

    public function setState(WorkflowStatus $state, array $context = []): void
    {
        $this->state = $state;
    }
}
