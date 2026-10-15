<?php
declare(strict_types=1);
namespace Platform\Workflow\Contract;

use Platform\Workflow\Model\WorkflowRunIdentity;

/** Exclusive technical Run/Step writer for enrolled generic Agent and Tool invocations. */
interface WorkflowKernelExecutionStoreInterface
{
    public function beginKernel(
        WorkflowRunIdentity $identity,string $kind,string $sourceId,string $capability,
        string $inputDigest,string $correlation,string $worker,int $leaseSeconds
    ):string;
    public function completeKernel(
        WorkflowRunIdentity $identity,string $kind,string $token,string $receipt,string $correlation
    ):void;
    public function quarantineKernel(WorkflowRunIdentity $identity,string $kind,string $token):void;
}
