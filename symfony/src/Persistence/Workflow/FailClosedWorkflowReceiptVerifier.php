<?php
declare(strict_types=1);

namespace App\Persistence\Workflow;

use DomainException;
use Platform\Workflow\Contract\WorkflowReceiptVerifierInterface;
use Platform\Workflow\Model\WorkflowReceiptAttestation;
use Platform\Workflow\Model\WorkflowReceiptRequest;

/** No receipt is accepted as proof until an independently wired verifier exists. */
final readonly class FailClosedWorkflowReceiptVerifier implements WorkflowReceiptVerifierInterface
{
    public function attest(WorkflowReceiptRequest $request): WorkflowReceiptAttestation
    {
        throw new DomainException('Durable Workflow canonical receipts are not enabled.');
    }
}
