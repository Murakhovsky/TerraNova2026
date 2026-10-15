<?php
declare(strict_types=1);

/** Recover native Agent/Tool facts, never external execution; require two managers. */
$root=dirname(__DIR__,2);
$store=(string)file_get_contents($root.'/symfony/src/Persistence/Workflow/DoctrineWorkflowExecutionStore.php');
$manager=(string)file_get_contents($root.'/symfony/src/Persistence/Workflow/DoctrineKernelExecutionRecoveryManager.php');
$controller=(string)file_get_contents($root.'/symfony/src/Http/Api/V1/Controller/WorkflowRecoveryController.php');
foreach([
    [$store,'public function reconcileKernelApproved('],
    [$store,'cos_workflow_recovery_decisions'],
    [$store,'$this->kernelEvidence->assertCompletion('],
    [$store,'WorkflowDefinitionFingerprint::digest('],
    [$store,'claim_expires_at'],
    [$store,'approved_by'],
    [$manager,'public function request('],
    [$manager,'public function approve('],
    [$manager,'public function reconcile('],
    [$manager,'requested_by<>:by'],
    [$manager,'WorkflowRecoveryResolution::VERIFIED_COMPLETE'],
    [$manager,'$this->store->reconcileKernelApproved('],
    [$controller,'DoctrineKernelExecutionRecoveryManager'],
    [$controller,'$this->kernel->request('],
    [$controller,'$this->kernel->approve('],
    [$controller,'$this->kernel->reconcile('],
    [$controller,'$this->kernelInspector->inspectTenant('],
] as [$source,$needle]) {
    if(!str_contains($source,$needle)) {
        throw new RuntimeException('Missing two-manager canonical Kernel recovery safety: '.$needle);
    }
}
if(preg_match('/\\b(?:INSERT\\s+INTO|UPDATE|DELETE\\s+FROM)\\s+cos_workflow_(?:runs|steps)\\b/i',$manager)) {
    throw new RuntimeException('Kernel recovery manager bypasses Platform single writer.');
}
foreach(['->run(', '->invoke(', '->execute(', '->enqueue(', '->submit('] as $call) {
    if(str_contains($manager,$call)) throw new RuntimeException('Recovery attempts to re-execute external effect.');
}
echo "Kernel Agent/Tool two-manager native-receipt recovery architecture PASS.\n";
