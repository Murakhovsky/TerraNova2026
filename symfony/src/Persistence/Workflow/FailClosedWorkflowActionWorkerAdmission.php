<?php
declare(strict_types=1);

namespace App\Persistence\Workflow;

use Kernel\Action\Action;
use Kernel\Action\Contract\WorkflowActionWorkerAdmissionInterface;
use Kernel\Action\Contract\FailClosedWorkflowAdmissionMarkerInterface;
use Kernel\Execution\ExecutionFailureException;

/** Production default until canonical worker parity and cutover are accepted. */
final readonly class FailClosedWorkflowActionWorkerAdmission implements WorkflowActionWorkerAdmissionInterface, FailClosedWorkflowAdmissionMarkerInterface
{
    public function assertAuthorized(Action $action): void
    {
        throw ExecutionFailureException::policyDenied(
            'Workflow-owned external Action execution is disabled pending canonical worker cutover.'
        );
    }
}
