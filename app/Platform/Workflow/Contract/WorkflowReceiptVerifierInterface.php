<?php
declare(strict_types=1);

namespace Platform\Workflow\Contract;

use Platform\Workflow\Model\WorkflowReceiptAttestation;
use Platform\Workflow\Model\WorkflowReceiptRequest;

interface WorkflowReceiptVerifierInterface
{
    /** Read-only attestation of an independently persisted canonical effect. */
    public function attest(WorkflowReceiptRequest $request): WorkflowReceiptAttestation;
}
