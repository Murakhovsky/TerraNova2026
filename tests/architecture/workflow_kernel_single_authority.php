<?php
declare(strict_types=1);

/** P0 guard: general Kernel Agent/Tool never become a second technical SQL writer. */
$root=dirname(__DIR__,2);
$store=(string)file_get_contents($root.'/symfony/src/Persistence/Workflow/DoctrineWorkflowExecutionStore.php');
$fence=(string)file_get_contents($root.'/symfony/src/Persistence/Workflow/DoctrineKernelExecutionFence.php');
$evidence=(string)file_get_contents($root.'/symfony/src/Persistence/Workflow/DoctrineKernelExecutionEvidence.php');
$agent=(string)file_get_contents($root.'/app/Kernel/Agent/Service/AgentRuntime.php');
$agentJob=(string)file_get_contents($root.'/app/Kernel/Queue/Handler/AgentRunJobHandler.php');
$tool=(string)file_get_contents($root.'/app/Kernel/Tool/Service/ToolRuntime.php');
$services=(string)file_get_contents($root.'/symfony/config/services.yaml');
$must=[
 [$store,'implements WorkflowExecutionStoreInterface'],
 [$store,'WorkflowKernelExecutionStoreInterface'],
 [$store,'public function beginKernel('],
 [$store,'public function completeKernel('],
 [$store,'public function quarantineKernel('],
 [$store,'claim_expires_at>UTC_TIMESTAMP(6)'],
 [$fence,'cos_kernel_execution_rollouts'],
 [$fence,'assertAgentAdmitted('],
 [$agent,'assertAgentAdmitted($invocation)'],
 [$agentJob,'beginAgent($job, $agentName)'],
 [$agentJob,'completeAgent($claim, $execution->runId)'],
 [$agentJob,'quarantine($claim)'],
 [$tool,'beginTool($invocation,$definition,$execution->id)'],
 [$tool,'$claim!==null ? 1 :'],
 [$tool,'completeTool($claim,$execution->id)'],
 [$tool,'quarantine($claim)'],
 [$evidence,'FROM cos_agent_runs'],
 [$evidence,'FROM cos_audit_log'],
 [$evidence,'__kernel_source_job_id'],
 [$evidence,'cos_policy_evaluations'],
 [$evidence,'cos_approvals'],
 [$evidence,'cos_action_attempts'],
 [$services,'WorkflowKernelExecutionStoreInterface:'],
 [$services,'KernelCanonicalExecutionFenceInterface:'],
 [$services,'$kernelEvidence:'],
 [$services,'$canonical:'],
];
foreach($must as [$source,$needle])if(!str_contains($source,$needle)) {
 throw new RuntimeException('Missing canonical Kernel fencing assertion: '.$needle);
}
foreach([
 'symfony/src/Persistence/Workflow/DoctrineKernelExecutionFence.php',
 'symfony/src/Persistence/Workflow/DoctrineKernelExecutionEvidence.php',
 'app/Kernel/Agent/Service/AgentRuntime.php',
 'app/Kernel/Queue/Handler/AgentRunJobHandler.php',
 'app/Kernel/Tool/Service/ToolRuntime.php',
] as $path) {
 $source=(string)file_get_contents($root.'/'.$path);
 if(preg_match('/\\b(?:INSERT\\s+INTO|UPDATE|DELETE\\s+FROM)\\s+cos_workflow_(?:runs|steps)\\b/i',$source)) {
    throw new RuntimeException('Second canonical technical writer: '.$path);
 }
}
if(!is_file($root.'/app/migrations/20261015_000144_kernel_execution_rollouts.sql')
 || !is_file($root.'/tests/integration/workflow_kernel_canonical_mysql.php')) {
 throw new RuntimeException('Generic Kernel canonical schema/real MySQL acceptance missing.');
}
echo "Generic Agent/Tool canonical single-technical-writer architecture gate PASSED.\n";
