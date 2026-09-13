<?php
declare(strict_types=1);

use Kernel\Action\Action;
use Kernel\Action\ActionStatus;
use Kernel\Action\Contract\ActionHandlerInterface;
use Kernel\Action\ExecutionResult;
use Kernel\Action\Service\ActionExecutor;
use Kernel\Operations\Service\PeriodicMaintenanceGate;
use Kernel\Queue\Contract\JobHandlerInterface;
use Kernel\Queue\Contract\JobQueueInterface;
use Kernel\Queue\Job;
use Kernel\Queue\Service\QueueWorker;

require dirname(__DIR__, 2) . '/vendor/autoload.php';

final class CachedActionHandler implements ActionHandlerInterface
{
    public int $supportsCalls = 0;

    public function supports(string $actionType): bool
    {
        $this->supportsCalls++;
        return $actionType === 'test.action';
    }

    public function execute(Action $action): ExecutionResult
    {
        return ExecutionResult::success();
    }
}

final class RuntimeTestQueue implements JobQueueInterface
{
    public int $recoverCalls = 0;
    public int $completeCalls = 0;
    /** @var list<Job> */
    private array $jobs;

    /** @param list<Job> $jobs */
    public function __construct(array $jobs)
    {
        $this->jobs = $jobs;
    }

    public function enqueue(string $organizationId, string $type, array $payload, string $correlationId, ?string $idempotencyKey = null, int $maxAttempts = 5, int $timeoutSeconds = 60): string
    {
        throw new RuntimeException('Not used.');
    }

    public function claim(string $workerId): ?Job
    {
        return array_shift($this->jobs);
    }

    public function complete(Job $job): void
    {
        $this->completeCalls++;
    }

    public function fail(Job $job, string $error): void
    {
        throw new RuntimeException('Job should not fail: ' . $error);
    }

    public function recoverTimedOut(): int
    {
        $this->recoverCalls++;
        return 0;
    }

    public function replayDead(?string $organizationId = null, ?string $jobId = null): int
    {
        return 0;
    }
}

final class CachedJobHandler implements JobHandlerInterface
{
    public int $supportsCalls = 0;
    public int $handleCalls = 0;

    public function supports(string $type): bool
    {
        $this->supportsCalls++;
        return $type === 'test.job';
    }

    public function handle(Job $job): void
    {
        $this->handleCalls++;
    }
}

$actionHandler = new CachedActionHandler();
$executor = new ActionExecutor([$actionHandler]);
foreach (['action-1', 'action-2'] as $id) {
    $executor->execute(new Action(
        $id,
        'org-1',
        'test.action',
        null,
        null,
        [],
        'TEST',
        $id,
        'AUTO',
        'LOW',
        null,
        new DateTimeImmutable(),
        ActionStatus::Queued,
    ));
}
if ($actionHandler->supportsCalls !== 1) {
    throw new RuntimeException('Action handler lookup must be cached by action type.');
}

$queue = new RuntimeTestQueue([
    new Job('job-1', 'org-1', 'test.job', [], 0, 5, 60, 'corr-1'),
    new Job('job-2', 'org-1', 'test.job', [], 0, 5, 60, 'corr-2'),
]);
$jobHandler = new CachedJobHandler();
$worker = new QueueWorker($queue, [$jobHandler], recoveryIntervalSeconds: 60);
$worker->runOne('worker-1');
$worker->runOne('worker-1');

if ($queue->recoverCalls !== 1) {
    throw new RuntimeException('Timed-out queue recovery must be throttled instead of running before every job.');
}
if ($queue->completeCalls !== 2 || $jobHandler->handleCalls !== 2) {
    throw new RuntimeException('Queue worker must process both jobs.');
}
if ($jobHandler->supportsCalls !== 1) {
    throw new RuntimeException('Job handler lookup must be cached by job type.');
}

$gate = new PeriodicMaintenanceGate(60);
if (!$gate->due() || $gate->due()) {
    throw new RuntimeException('Periodic maintenance gate must allow the first run and throttle immediate repeats.');
}

echo "COS runtime dispatch optimization invariant passed.\n";
