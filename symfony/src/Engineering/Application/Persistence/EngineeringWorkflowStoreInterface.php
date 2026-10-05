<?php
declare(strict_types=1);

namespace App\Engineering\Application\Persistence;

use App\Engineering\Domain\Workflow\WorkflowExecution;
use App\Engineering\Domain\Workflow\WorkflowTransition;

interface EngineeringWorkflowStoreInterface
{
    public function create(WorkflowExecution $workflow, string $workflowType = 'ENGINEERING'): void;
    public function activeIdForFeature(string $featureId): ?string;
    public function latestIdForFeature(string $featureId): ?string;

    /** @return array<string,mixed> */
    public function view(string $workflowId): array;

    public function markImmediate(string $workflowId): void;
    public function touchRuntime(string $workflowId, ?string $agentRunId = null, ?string $taskId = null): void;
    public function markRuntimeIssue(string $workflowId, string $health, string $reason, ?string $agentRunId = null, ?string $taskId = null): void;
    /** @return array{healthy:int,stale:int,stalled:int,waiting:int} */
    public function refreshRuntimeHealthForOrganization(string $organizationId, int $staleAfterSeconds = 600, int $stalledAfterSeconds = 1800): array;

    /** @return list<array{feature_id:string,workflow_id:string,state:string,priority:string,title:string,feature_status:string,started_at:string}> */
    public function resumable(int $limit = 20): array;

    /** @return list<array{feature_id:string,workflow_id:string,state:string,priority:string,title:string,feature_status:string,started_at:string}> */
    public function queueForOrganization(string $organizationId, int $limit = 100): array;

    /** @return list<array{feature_id:string,workflow_id:string,workflow_type:string,workflow_status:string,state:string,priority:string,title:string,feature_status:string,started_at:string,last_activity_at:string}> */
    public function activeForOrganization(string $organizationId, int $limit = 100): array;
    public function get(string $workflowId): WorkflowExecution;
    public function saveTransition(WorkflowExecution $workflow, WorkflowTransition $transition): void;
}
