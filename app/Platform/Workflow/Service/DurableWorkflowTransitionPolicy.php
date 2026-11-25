<?php
declare(strict_types=1);

namespace Platform\Workflow\Service;

use InvalidArgumentException;

/** State transition policy. Pure technical state validation. Policy/Approval/Action remain separate. */
final class DurableWorkflowTransitionPolicy
{
    public const ACTION_EFFECTS = ['internal','external','financial','irreversible'];
    public const SUPPORTED_EFFECTS = ['none','internal','external','financial','irreversible'];

    public static function isActionEffect(mixed $effect): bool
    {
        return in_array($effect,self::ACTION_EFFECTS,true);
    }

    public static function requiresExplicitPolicy(mixed $effect): bool
    {
        return in_array($effect,['financial','irreversible'],true);
    }

    /** Classification is not authorization. All canonical mutations still require Policy and Approval. */
    public static function acceptsActionContract(\Kernel\Module\CapabilityContract $contract):bool
    {
        return self::isActionEffect($contract->sideEffectLevel)
            && $contract->kind==='command'
            && str_starts_with($contract->executionBinding,'action:')
            && $contract->approvalPolicy==='required'
            && $contract->idempotency==='required'
            && (!self::requiresExplicitPolicy($contract->sideEffectLevel)
                || ($contract->retryPolicy['max_attempts']??null)===1);
    }

    private const RUN_TRANSITIONS = [
        'created' => ['running', 'cancelled'],
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
