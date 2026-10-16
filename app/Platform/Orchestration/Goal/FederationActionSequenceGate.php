<?php
declare(strict_types=1);

namespace Platform\Orchestration\Goal;

use DomainException;

/**
 * One authority-independent, fail-closed DAG order check shared by the
 * Action intent admission and the last-moment Action worker admission.
 * It never authorizes a business mutation on its own.
 */
final readonly class FederationActionSequenceGate
{
    /**
     * @param list<array<string,mixed>> $approvedSteps
     * @param list<array<string,mixed>> $persistedSteps
     */
    public function assertSelectable(
        array $approvedSteps,
        array $persistedSteps,
        string $stepId,
        string $requiredState,
    ): void {
        if (!in_array($requiredState, ['pending', 'claimed'], true)) {
            throw new DomainException('Unsupported Federation Action admission phase.');
        }
        $next = (new FederationStepCursor())->select($approvedSteps, $persistedSteps);
        if ($next['state'] !== $requiredState || $next['step_id'] !== $stepId) {
            throw new DomainException(
                'Federation Action cannot bypass DAG prerequisites or another active external claim.',
            );
        }
    }
}
