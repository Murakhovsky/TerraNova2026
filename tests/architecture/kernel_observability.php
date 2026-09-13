<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);
require $root . '/vendor/autoload.php';

function assertObservability(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException($message);
}

$kernelVersion = (string) file_get_contents($root . '/app/Kernel/Module/KernelVersion.php');
assertObservability(str_contains($kernelVersion, "VERSION = '0.11.8'"), 'Kernel version must be 0.11.8.');

$worker = (string) file_get_contents($root . '/app/Kernel/Queue/Service/QueueWorker.php');
foreach ([
    'cos.execution.queue_wait_ms',
    'cos.execution.lock_wait_ms',
    'cos.execution.execution_ms',
    'cos.execution.retry_count',
    'cos.execution.failures',
    'cos.execution.throughput',
    "'correlation_id' => \$job->correlationId",
] as $needle) {
    assertObservability(str_contains($worker, $needle), 'Queue observability invariant missing: ' . $needle);
}

$actionService = (string) file_get_contents($root . '/app/Kernel/Action/Service/ActionService.php');
foreach ([
    'cos.execution.execution_ms',
    'cos.execution.retry_count',
    'cos.execution.failures',
    'cos.execution.throughput',
    "'correlation_id' => \$action->correlationId",
] as $needle) {
    assertObservability(str_contains($actionService, $needle), 'Action observability invariant missing: ' . $needle);
}

$external = (string) file_get_contents($root . '/app/Kernel/Resilience/ExternalCallExecutor.php');
foreach ([
    'cos.execution.external_call_ms',
    'cos.execution.retry_count',
    'cos.execution.failures',
    'cos.execution.throughput',
    'failure_kind',
] as $needle) {
    assertObservability(str_contains($external, $needle), 'External-call observability invariant missing: ' . $needle);
}

$queue = (string) file_get_contents($root . '/app/Infrastructure/Platform/Persistence/MySql/Queue/MysqlJobQueue.php');
assertObservability(str_contains($queue, 'hrtime(true)'), 'Tenant lock wait must be measured with a monotonic clock.');
assertObservability(str_contains($queue, "new DateTimeImmutable((string) \$row['available_at'])"),
    'Queue admission must preserve available_at for queue-wait telemetry.');

// IDs belong in structured logs, never directly in metric calls/labels.
foreach ([$worker, $actionService, $external] as $source) {
    preg_match_all('/metrics\?->record\((.*?)\);/s', $source, $metricCalls);
    foreach ($metricCalls[1] ?? [] as $call) {
        foreach (["'correlation_id'", "'job_id'", "'action_id'", "'organization_id'"] as $identifier) {
            assertObservability(!str_contains($call, $identifier),
                'High-cardinality identifier leaked into a metric call: ' . $identifier);
        }
    }
}

$job = new Kernel\Queue\Job(
    'job-1', 'org-1', 'test.job', [], 1, 3, 60, 'corr-1', null, 'worker-1',
    new DateTimeImmutable('-2 seconds'), 12.5,
);
assertObservability(($job->queueWaitMilliseconds() ?? 0) >= 1000, 'Queue-wait duration should be observable and non-negative.');
assertObservability($job->lockWaitMs === 12.5, 'Lock-wait duration must survive queue hydration.');

echo "COS Kernel V0.11.8 observability invariant passed.\n";
