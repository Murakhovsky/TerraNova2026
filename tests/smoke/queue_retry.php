<?php
declare(strict_types=1);

use Kernel\Queue\Contract\JobHandlerInterface;
use Kernel\Queue\Contract\JobQueueInterface;
use Kernel\Queue\Job;
use Kernel\Queue\Service\QueueWorker;

$root = dirname(__DIR__, 2);
spl_autoload_register(static function (string $class) use ($root): void {
    if (str_starts_with($class, 'Kernel\\')) {
        $file = $root . '/app/Kernel/' . str_replace('\\', '/', substr($class, 7)) . '.php';
        if (is_file($file)) require $file;
    }
});

$queue = new class implements JobQueueInterface {
    public string $status = 'PENDING'; public int $attempts = 0; public ?string $error = null;
    public function enqueue(string $organizationId, string $type, array $payload, string $correlationId, ?string $idempotencyKey = null, int $maxAttempts = 5, int $timeoutSeconds = 60): string { return 'retry-job'; }
    public function claim(string $workerId): ?Job {
        if (!in_array($this->status, ['PENDING', 'FAILED'], true)) return null;
        $this->status = 'RUNNING'; $this->attempts++;
        return new Job('retry-job', 'default', 'ALWAYS_FAILS', [], $this->attempts, 3, 10, 'correlation-retry');
    }
    public function complete(Job $job): void { $this->status = 'COMPLETED'; }
    public function fail(Job $job, string $error): void { $this->error = $error; $this->status = $job->attempts >= $job->maxAttempts ? 'DEAD' : 'FAILED'; }
    public function recoverTimedOut(): int { return 0; }
    public function replayDead(?string $organizationId = null, ?string $jobId = null): int { if ($this->status !== 'DEAD') return 0; $this->status = 'PENDING'; $this->attempts = 0; $this->error = null; return 1; }
};
$handler = new class implements JobHandlerInterface {
    public function supports(string $type): bool { return true; }
    public function handle(Job $job): void { throw new RuntimeException('temporary upstream failure'); }
};
$worker = new QueueWorker($queue, [$handler]);
for ($i = 0; $i < 4; $i++) $worker->runOne('retry-worker');

if ($queue->attempts !== 3 || $queue->status !== 'DEAD' || $queue->error !== 'temporary upstream failure') {
    throw new RuntimeException('Retry/dead-letter lifecycle failed.');
}
$replayed = $queue->replayDead('default', 'retry-job');
if ($replayed !== 1 || $queue->status !== 'PENDING' || $queue->attempts !== 0) {
    throw new RuntimeException('Dead job replay failed.');
}
echo "Queue retry/dead-letter smoke test passed.\n";
