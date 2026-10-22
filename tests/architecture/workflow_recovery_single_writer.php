<?php
declare(strict_types=1);

$root=dirname(__DIR__,2);
$freeze=json_decode((string)file_get_contents(
    $root.'/resources/architecture/cos-execution-authority-freeze.json'),true,512,JSON_THROW_ON_ERROR);
$owner='symfony/src/Persistence/Workflow/DoctrineWorkflowExecutionStore.php';
foreach(['cos_workflow_runs','cos_workflow_steps'] as $table) {
    if(($freeze['allowed_writers'][$table]??null)!==[$owner]) {
        throw new RuntimeException('P0 canonical Run/Step requires exactly one technical SQL writer: '.$table);
    }
}
$manager=(string)file_get_contents(
    $root.'/symfony/src/Persistence/Workflow/DoctrineWorkflowRecoveryManager.php');
$store=(string)file_get_contents($root.'/'.$owner);
$interface=(string)file_get_contents(
    $root.'/app/Platform/Workflow/Contract/WorkflowApprovedRecoveryWriterInterface.php');
$services=(string)file_get_contents($root.'/symfony/config/services.yaml');
if(preg_match('/\\b(?:INSERT\\s+INTO|UPDATE|DELETE\\s+FROM)\\s+cos_workflow_(?:runs|steps)\\b/i',$manager)) {
    throw new RuntimeException('Operator RecoveryManager still writes technical Run/Step directly.');
}
foreach([
    [$store,'implements WorkflowExecutionStoreInterface'],
    [$store,'WorkflowApprovedRecoveryWriterInterface'],
    [$store,'public function applyApprovedRecovery('],
    [$store,'cos_workflow_recovery_decisions'],
    [$store,'approved_by'],
    [$store,'claim_expires_at'],
    [$manager,'WorkflowApprovedRecoveryWriterInterface'],
    [$manager,'$this->writer->applyApprovedRecovery('],
    [$interface,'public function applyApprovedRecovery('],
    [$services,'WorkflowApprovedRecoveryWriterInterface:'],
] as [$body,$needle]) {
    if(!str_contains($body,$needle))throw new RuntimeException('Missing recovery single-writer proof: '.$needle);
}
if(!is_file($root.'/tests/integration/workflow_safe_recovery_mysql.php')) {
    throw new RuntimeException('Real MySQL recovery CAS acceptance missing.');
}
echo "Workflow recovery single technical writer PASS: manager decision only, Platform Store Run/Step CAS.\n";
