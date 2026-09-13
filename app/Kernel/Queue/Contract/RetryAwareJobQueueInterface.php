<?php
declare(strict_types=1);

namespace Kernel\Queue\Contract;

use Kernel\Queue\Job;

interface RetryAwareJobQueueInterface extends JobQueueInterface
{
    public function failWithRetryPolicy(Job $job, string $error, bool $retryable): void;
}
