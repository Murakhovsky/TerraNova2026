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
    public function markImmediate(string $workflowId): void;

    /** @return list<array{feature_id:string,workflow_id:string,state:string,priority:string,title:string,feature_status:string,started_at:string}> */
    public function resumable(int $limit = 20): array;

    /** @return list<array{feature_id:string,workflow_id:string,state:string,priority:string,title:string,feature_status:string,started_at:string}> */
    public function queueForOrganization(string $organizationId, int $limit = 100): array;

    /** @return list<array{feature_id:string,workflow_id:string,workflow_type:string,workflow_status:string,state:string,priority:string,title:string,feature_status:string,started_at:string,last_activity_at:string}> */
    public function activeForOrganization(string $organizationId, int $limit = 100): array;
    public function get(string $workflowId): WorkflowExecution;
    public function saveTransition(WorkflowExecution $workflow, WorkflowTransition $transition): void;
}
