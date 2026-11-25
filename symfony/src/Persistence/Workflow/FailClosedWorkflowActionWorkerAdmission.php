<?php
declare(strict_types=1);

namespace App\Persistence\Workflow;

use Kernel\Action\Action;
use Kernel\Action\Contract\WorkflowActionWorkerAdmissionInterface;
use Kernel\Execution\ExecutionFailureException;

/** Production default until canonical worker parity and cutover are accepted. */
final readonly class FailClosedWorkflowActionWorkerAdmission implements WorkflowActionWorkerAdmissionInterface
{
    public function __construct(
        private ?DoctrineWorkflowActionWorkerAdmission $candidate=null,
        private string $enabledOrganization='',
    ) {}

    public function assertAuthorized(Action $action): void
    {
        // An explicit single-tenant cutover is separate from Plan creation rollout.
        // Empty configuration or unknown tenant remains denied by default.
        if($this->candidate===null||$this->enabledOrganization===''
            ||!hash_equals($this->enabledOrganization,$action->organizationId)){
            throw ExecutionFailureException::policyDenied(
                'Workflow-owned Action execution is disabled pending canonical worker cutover.'
            );
        }
        $this->candidate->assertAuthorized($action);
    }
}
