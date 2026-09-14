<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);
require $root . '/vendor/autoload.php';

use Kernel\Action\ExecutionResult;
use Kernel\Execution\ExecutionFailureClassifier;
use Kernel\Execution\ExecutionFailureException;
use Kernel\Execution\ExecutionFailureKind;
use Kernel\Llm\LlmBudgetExceededException;
use Kernel\Llm\LlmProviderException;
use Kernel\Queue\Contract\JobHandlerInterface;
use Kernel\Queue\Contract\RetryAwareJobQueueInterface;
use Kernel\Queue\Job;
use Kernel\Queue\Service\QueueWorker;

final class RecordingJobQueue implements RetryAwareJobQueueInterface
{
    public ?bool $lastRetryable = null;
    public ?string $lastError = null;
    private ?Job $job;

    public function __construct(Job $job)
    {
        $this->job = $job;
    }

    public function enqueue(string $organizationId, string $type, array $payload, string $correlationId, ?string $idempotencyKey = null, int $maxAttempts = 5, int $timeoutSeconds = 60): string
    {
        throw new LogicException('Not used by this test.');
    }

    public function claim(string $workerId): ?Job
    {
        $job = $this->job;
        $this->job = null;
        return $job;
    }

    public function complete(Job $job): void
    {
        throw new RuntimeException('Failure test unexpectedly completed a job.');
    }

    public function fail(Job $job, string $error): void
    {
        $this->lastError = $error;
        $this->lastRetryable = true;
    }

    public function failWithRetryPolicy(Job $job, string $error, bool $retryable): void
    {
        $this->lastError = $error;
        $this->lastRetryable = $retryable;
    }

    public function recoverTimedOut(): int { return 0; }
    public function replayDead(?string $organizationId = null, ?string $jobId = null): int { return 0; }
}

final readonly class ThrowingJobHandler implements JobHandlerInterface
{
    public function __construct(private Throwable $failure) {}
    public function supports(string $type): bool { return $type === 'test.failure'; }
    public function handle(Job $job): void { throw $this->failure; }
}

function assertFailureSemantics(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException($message);
}

assertFailureSemantics(
    ExecutionFailureClassifier::classify(new LogicException('bad contract')) === ExecutionFailureKind::Permanent,
    'Logic errors must be classified as permanent.',
);
assertFailureSemantics(
    ExecutionFailureClassifier::classify(new RuntimeException('temporary runtime failure')) === ExecutionFailureKind::Retryable,
    'Unknown runtime failures must preserve legacy retry behavior.',
);
assertFailureSemantics(
    ExecutionFailureClassifier::classify(ExecutionFailureException::externalUnavailable('upstream down')) === ExecutionFailureKind::ExternalUnavailable,
    'Explicit external failures lost their classification.',
);
assertFailureSemantics(
    ExecutionFailureClassifier::classify(new LlmBudgetExceededException('org-1', 10.0, 10.0, 'USD')) === ExecutionFailureKind::BudgetExceeded,
    'LLM budget failures must be terminal budget failures.',
);
assertFailureSemantics(
    ExecutionFailureClassifier::classify(new LlmProviderException('openai', true, 'upstream timeout')) === ExecutionFailureKind::ExternalUnavailable,
    'Retryable provider failures must be classified as external unavailability.',
);
assertFailureSemantics(
    ExecutionFailureClassifier::classify(new LlmProviderException('openai', false, 'invalid request', 400)) === ExecutionFailureKind::Permanent,
    'Non-retryable provider failures must be terminal.',
);

$actionFailure = ExecutionResult::failure('business rejection');
assertFailureSemantics(
    $actionFailure->failureKind === ExecutionFailureKind::Permanent && !$actionFailure->retryable,
    'Explicit action failures must default to permanent semantics.',
);
$externalFailure = ExecutionResult::failure(
    'provider unavailable',
    failureKind: ExecutionFailureKind::ExternalUnavailable,
);
assertFailureSemantics($externalFailure->retryable, 'External-unavailable action failures must be retryable.');

$job = new Job('job-1', 'org-1', 'test.failure', [], 1, 5, 60, 'corr-1');
$queue = new RecordingJobQueue($job);
$worker = new QueueWorker($queue, [new ThrowingJobHandler(ExecutionFailureException::policyDenied('disabled'))], recoveryIntervalSeconds: 3600);
$worker->runOne('worker-1');
assertFailureSemantics($queue->lastRetryable === false, 'Policy-denied jobs must go terminal without retry.');

$job = new Job('job-2', 'org-1', 'test.failure', [], 1, 5, 60, 'corr-2');
$queue = new RecordingJobQueue($job);
$worker = new QueueWorker($queue, [new ThrowingJobHandler(new RuntimeException('network wobble'))], recoveryIntervalSeconds: 3600);
$worker->runOne('worker-1');
assertFailureSemantics($queue->lastRetryable === true, 'Unknown runtime failures must remain retryable.');

$mysqlSource = (string) file_get_contents(
    $root . '/app/Infrastructure/Platform/Persistence/MySql/Queue/MysqlJobQueue.php'
);
assertFailureSemantics(
    str_contains($mysqlSource, '$dead = !$retryable || $job->attempts >= $job->maxAttempts;'),
    'MysqlJobQueue must dead-letter non-retryable jobs immediately.',
);

echo "Execution failure semantics passed.\n";
