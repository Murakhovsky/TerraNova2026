<?php
declare(strict_types=1);
/** P0: never reopen canonical Agent/Tool effects through generic legacy queues. */
$root=dirname(__DIR__,2);
$queue=(string)file_get_contents($root.'/app/Infrastructure/Platform/Persistence/MySql/Queue/MysqlJobQueue.php');
$actions=(string)file_get_contents($root.'/app/Infrastructure/Platform/Persistence/MySql/Action/MysqlActionRepository.php');
$handler=(string)file_get_contents($root.'/app/Kernel/Queue/Handler/ActionExecutionJobHandler.php');
$evidence=(string)file_get_contents($root.'/symfony/src/Persistence/Workflow/DoctrineKernelExecutionEvidence.php');
$fence=(string)file_get_contents($root.'/symfony/src/Persistence/Workflow/DoctrineKernelExecutionFence.php');
foreach([
 [$queue,'CANONICAL_OWNED_JOB_SQL'],
 [$queue,'kernel.agent_execution'],
 [$queue,'kernel.tool.invoke'],
 [$queue,'cos_kernel_execution_rollouts'],
 [$queue,'public function recoverTimedOut()'],
 [$queue,'public function replayDead('],
 [$queue,'public function failWithRetryPolicy('],
 [$queue,'AND (jobs.attempts = 0 OR NOT '],
 [$actions,"type <> 'kernel.tool.invoke'"],
 [$handler,'$existing?->type === \'kernel.tool.invoke\''],
 [$handler,'ExecutionFailureException::permanent('],
 [$evidence,"locked_at"],
 [$evidence,"timeout_seconds"],
 [$fence,"locked_at"],
 [$fence,"timeout_seconds"],
] as [$content,$must]) {
 if(!str_contains($content,$must))throw new RuntimeException('P0 failover guard missing: '.$must);
}
if(!is_file($root.'/tests/integration/workflow_kernel_queue_failover_mysql.php')) {
 throw new RuntimeException('Real MySQL worker crash/replay acceptance missing.');
}
echo "Kernel Agent/Tool queue failover architecture PASS: immutable Run protection, no legacy Action redispatch.\n";
