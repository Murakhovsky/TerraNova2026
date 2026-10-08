<?php
declare(strict_types=1);

namespace Platform\Orchestration\Goal;

use DomainException;

/**
 * Deterministic serialized DAG cursor over an immutable approved Plan.
 * Multiple independent branches may be represented, but at most ONE external
 * Action can be claimed at a time. No implicit parallel dispatch or replay.
 */
final readonly class FederationStepCursor
{
    /**
     * @param list<array<string,mixed>> $planSteps
     * @param list<array<string,mixed>> $persistedSteps
     * @return array{state:string,step_id:?string,completed:list<string>}
     */
    public function select(array $planSteps, array $persistedSteps): array
    {
        $dependencies = (new FederationStepDependencyGraph())->resolve($planSteps);
        if (count($planSteps) !== count($persistedSteps)) {
            throw new DomainException('Federation plan/Run step topology differs.');
        }
        $byId = [];
        foreach ($persistedSteps as $stored) {
            $id = $stored['step_id'] ?? null;
            if (!is_string($id) || $id === '' || isset($byId[$id])) {
                throw new DomainException('Federation Run has malformed or duplicate persisted steps.');
            }
            $byId[$id] = $stored;
        }

        $completed = [];
        $completedSet = [];
        $pending = [];
        $active = null;
        foreach ($planSteps as $approved) {
            $id = $approved['id'];
            $stored = $byId[$id] ?? null;
            if ($stored === null
                || ($approved['capability_id'] ?? null) !== ($stored['capability_id'] ?? null)
                || ($approved['capability_version'] ?? null) !== ($stored['capability_version'] ?? null)
                || ($approved['side_effect_level'] ?? null) !== ($stored['side_effect_level'] ?? null)
                || ($approved['side_effect_level'] ?? null) !== 'external') {
                throw new DomainException('Federation Action topology/capability snapshot drift.');
            }
            $state = $stored['state'] ?? null;
            if ($state === 'completed') {
                $completed[] = $id;
                $completedSet[$id] = true;
            } elseif ($state === 'pending') {
                $pending[] = $id;
            } elseif ($state === 'claimed' || $state === 'ambiguous') {
                if ($active !== null) {
                    throw new DomainException('Multiple concurrent external Actions are unsupported.');
                }
                $active = ['state' => $state, 'step_id' => $id];
            } else {
                throw new DomainException('Unsupported or unsafe Federation Step state.');
            }
        }
        if (count($byId) !== count($dependencies)) {
            throw new DomainException('Unexpected Federation Run step was inserted.');
        }

        // Even previously completed steps must have had every prerequisite
        // completed. This is a topology invariant, not a trusted success receipt.
        foreach ($completed as $id) {
            foreach ($dependencies[$id] as $prerequisite) {
                if (!isset($completedSet[$prerequisite])) {
                    throw new DomainException('Completed Federation Step skipped a DAG prerequisite.');
                }
            }
        }
        if ($active !== null) {
            foreach ($dependencies[$active['step_id']] as $prerequisite) {
                if (!isset($completedSet[$prerequisite])) {
                    throw new DomainException('Claimed external Action skipped a DAG prerequisite.');
                }
            }
            return ['state' => $active['state'], 'step_id' => $active['step_id'],
                'completed' => $completed];
        }

        // Deterministic tie break follows approved Plan order, not SQL ordering.
        // Every completed receipt will be re-attested by the orchestrator before
        // the next external Action is submitted.
        foreach ($pending as $id) {
            $ready = true;
            foreach ($dependencies[$id] as $prerequisite) {
                if (!isset($completedSet[$prerequisite])) {
                    $ready = false;
                    break;
                }
            }
            if ($ready) {
                return ['state' => 'pending', 'step_id' => $id, 'completed' => $completed];
            }
        }
        if ($pending !== []) {
            throw new DomainException('Federation DAG has pending steps without a runnable prerequisite.');
        }
        return ['state' => 'complete', 'step_id' => null, 'completed' => $completed];
    }
}
