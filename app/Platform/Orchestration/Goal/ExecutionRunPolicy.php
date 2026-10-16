<?php
declare(strict_types=1);

namespace Platform\Orchestration\Goal;

use Platform\Workflow\Service\DurableWorkflowTransitionPolicy;

/**
 * Compatibility entrypoint for persisted Federation Run/Step transitions.
 * Universal fail-closed state policy lives in Platform Workflow.
 */
final class ExecutionRunPolicy
{
    public static function canTransition(string $from, string $to, bool $step = false): bool
    {
        return DurableWorkflowTransitionPolicy::canTransition($from, $to, $step);
    }

    public static function assertTransition(string $from, string $to, bool $step = false): void
    {
        DurableWorkflowTransitionPolicy::assertTransition($from, $to, $step);
    }

    public static function canAutoRetry(string $sideEffectLevel, string $lastState): bool
    {
        return DurableWorkflowTransitionPolicy::canAutoRetry($sideEffectLevel, $lastState);
    }
}
