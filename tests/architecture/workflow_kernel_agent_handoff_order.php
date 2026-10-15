<?php
declare(strict_types=1);

/**
 * Do not mark generic canonical Agent technical Run completed while Action
 * proposal handoff is still pending. A partial proposal/queue failure must
 * quarantine the claim and NEVER re-invoke the LLM on the same source job.
 */
$root=dirname(__DIR__,2);
$source=(string)file_get_contents($root.'/app/Kernel/Queue/Handler/AgentRunJobHandler.php');
$claim=strpos($source,'beginAgent($job, $agentName)');
$publish=strpos($source,'$this->policies->submit(');
$queue=strpos($source,'$this->queue->enqueue(');
$complete=strpos($source,'completeAgent($claim, $execution->runId)');
$quarantine=strpos($source,'quarantine($claim)');
if($claim===false||$publish===false||$queue===false||$complete===false||$quarantine===false
    || !($claim<$publish && $publish<$queue && $queue<$complete && $complete<$quarantine)
    || !str_contains($source,"'agent-job', $job->id")
    || !str_contains($source,"'action-execution:' . $action->id")
    || !str_contains($source,'catch (Throwable $error)')) {
    throw new RuntimeException('Agent canonical completion must follow proposal submission and queued Action handoff.');
}
echo "Canonical Agent handoff ordering architecture PASS: local output before handoff, receipt after, failures quarantined.\n";
