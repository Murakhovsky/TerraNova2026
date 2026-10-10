<?php
declare(strict_types=1);

namespace Platform\Orchestration\Goal;

use InvalidArgumentException;

/** State transition policy. A dispatcher must still use existing Policy/Approval/Action runtimes. */
final class ExecutionRunPolicy
{
    private const RUN_TRANSITIONS = [
        'pending' => ['running', 'cancelled'],
        'running' => ['waiting', 'completed', 'failed', 'ambiguous', 'cancelled'],
        'waiting' => ['running', 'cancelled', 'failed'],
        'ambiguous' => ['waiting', 'cancelled'],
        'failed' => [],
        'completed' => [],
        'cancelled' => [],
    ];

    private const STEP_TRANSITIONS = [
        'pending' => ['claimed', 'cancelled'],
        'claimed' => ['completed', 'failed', 'ambiguous', 'waiting'],
        'waiting' => ['claimed', 'cancelled'],
        'ambiguous' => ['waiting', 'cancelled'],
        'failed' => [],
        'completed' => [],
        'cancelled' => [],
    ];

    public static function canTransition(string $from, string $to, bool $step = false): bool
    {
        return in_array($to, ($step ? self::STEP_TRANSITIONS : self::RUN_TRANSITIONS)[$from] ?? [], true);
    }

    public static function assertTransition(string $from, string $to, bool $step = false): void
    {
        if (!self::canTransition($from, $to, $step)) {
            throw new InvalidArgumentException(sprintf('Forbidden %s transition: %s -> %s.', $step ? 'step' : 'run', $from, $to));
        }
    }

    /** Automatic retry is unsafe when an external side effect might have executed. */
    public static function canAutoRetry(string $sideEffectLevel, string $lastState): bool
    {
        return $sideEffectLevel === 'none' && $lastState === 'waiting';
    }
}
