<?php
declare(strict_types=1);

namespace App\Engineering\Application\Persistence;

use App\Engineering\Domain\Workflow\WorkflowExecution;
use App\Engineering\Domain\Workflow\WorkflowTransition;

interface EngineeringWorkflowStoreInterface
{
    public function create(WorkflowExecution $workflow): void;
    public function activeIdForFeature(string $featureId): ?string;
    public function get(string $workflowId): WorkflowExecution;
    public function saveTransition(WorkflowExecution $workflow, WorkflowTransition $transition): void;
}
