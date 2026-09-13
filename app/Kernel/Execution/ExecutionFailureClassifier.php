<?php
declare(strict_types=1);

namespace Kernel\Execution;

use LogicException;
use Throwable;

final class ExecutionFailureClassifier
{
    public static function classify(Throwable $exception): ExecutionFailureKind
    {
        if ($exception instanceof ClassifiedExecutionFailureInterface) {
            return $exception->failureKind();
        }

        // Domain/contract errors do not become valid by repeating the same work.
        if ($exception instanceof LogicException) {
            return ExecutionFailureKind::Permanent;
        }

        // Preserve the old queue behavior for unknown runtime/infrastructure failures:
        // retry unless a caller explicitly marks the failure as non-retryable.
        return ExecutionFailureKind::Retryable;
    }
}
