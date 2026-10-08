<?php
declare(strict_types=1);

namespace Platform\Orchestration\Goal;

use DomainException;

/**
 * Pure, deterministic scheduler for the initially supported linear Action
 * topology. Approved Plan array order is authoritative, never SQL row order.
 * No parallelism, branches, or implicit side effects are permitted.
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
        if ($planSteps === [] || !array_is_list($planSteps)
            || count($planSteps) > 100 || count($planSteps) !== count($persistedSteps)) {
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
        $seen = [];
        $next = null;
        foreach ($planSteps as $approved) {
            $id = $approved['id'] ?? null;
            if (!is_string($id) || !preg_match('/^[a-z0-9][a-z0-9_:-]{0,63}$/', $id)
                || isset($seen[$id])) {
                throw new DomainException('Federation approved Plan has invalid or duplicate steps.');
            }
            $seen[$id] = true;
            $stored = $byId[$id] ?? null;
            if ($stored === null
                || ($approved['capability_id'] ?? null) !== ($stored['capability_id'] ?? null)
                || ($approved['capability_version'] ?? null) !== ($stored['capability_version'] ?? null)
                || ($approved['side_effect_level'] ?? null) !== ($stored['side_effect_level'] ?? null)
                || ($approved['side_effect_level'] ?? null) !== 'external') {
                throw new DomainException('Federation Action topology/capability snapshot drift.');
            }
            if ($next !== null) {
                if ($stored['state'] !== 'pending') {
                    throw new DomainException('Federation step advanced out of approved sequential order.');
                }
                continue;
            }
            if ($stored['state'] === 'completed') {
                $completed[] = $id;
                continue;
            }
            if (!in_array($stored['state'], ['pending', 'claimed', 'ambiguous'], true)) {
                throw new DomainException('Unsupported or unsafe Federation Step state.');
            }
            $next = ['state' => (string) $stored['state'], 'step_id' => $id];
        }
        if (count($byId) !== count($seen)) {
            throw new DomainException('Unexpected Federation Run step was inserted.');
        }
        return ['state' => $next['state'] ?? 'complete',
            'step_id' => $next['step_id'] ?? null, 'completed' => $completed];
    }
}
