<?php
declare(strict_types=1);
namespace Platform\Workflow\Contract;

use Platform\Workflow\Model\WorkflowRunIdentity;

/** Independent native read-model verifier; NEVER writes technical state. */
interface WorkflowKernelExecutionEvidenceInterface
{
    public function assertBegin(
        WorkflowRunIdentity $identity,string $kind,string $sourceId,string $capability,
        string $inputDigest,string $correlation,string $worker
    ):void;
    public function assertCompletion(
        WorkflowRunIdentity $identity,string $kind,string $sourceId,string $capability,
        string $inputDigest,string $receipt,string $correlation
    ):void;
}
