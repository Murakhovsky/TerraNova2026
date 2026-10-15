<?php
declare(strict_types=1);

namespace Platform\Workflow\Service;

use DomainException;
use Platform\Workflow\Contract\WorkflowStepSchedulerInterface;
use Platform\Workflow\Model\WorkflowDefinitionSnapshot;
use Platform\Workflow\Model\WorkflowStepDecision;
use Platform\Workflow\Model\WorkflowStepSelectionState;
use Platform\Workflow\Model\WorkflowStepSnapshot;

/**
 * Shared pure DAG selection used by existing Federation execution and future
 * durable workflow runtime. Claim authority/receipt verification is NOT here.
 */
final readonly class WorkflowStepScheduler implements WorkflowStepSchedulerInterface
{
    public function __construct(
        private WorkflowDependencyGraph $graph = new WorkflowDependencyGraph(),
    ) {}

    public function select(
        WorkflowDefinitionSnapshot $definition,
        array $persistedSteps,
        array $allowedSideEffects = ['external'],
    ): WorkflowStepDecision {
        $approvedSteps = $definition->steps;
        $dependencies = $this->graph->resolve($approvedSteps);
        if (count($approvedSteps) !== count($persistedSteps)) {
            throw new DomainException('Workflow definition and persisted step topology differ.');
        }
        if ($allowedSideEffects === [] || count($allowedSideEffects) !== count(array_unique($allowedSideEffects))) {
            throw new DomainException('Workflow allowed side effect policy is invalid.');
        }

        $byId = [];
        foreach ($persistedSteps as $persisted) {
            if (!$persisted instanceof WorkflowStepSnapshot || isset($byId[$persisted->stepId])) {
                throw new DomainException('Invalid or duplicate persisted Workflow steps.');
            }
            $byId[$persisted->stepId] = $persisted;
        }

        $completed = [];
        $completedSet = [];
        $pending = [];
        $active = null;
        foreach ($approvedSteps as $approved) {
            $id = $approved['id'];
            $persisted = $byId[$id] ?? null;
            if ($persisted === null
                || ($approved['capability_id'] ?? null) !== $persisted->capabilityId
                || ($approved['capability_version'] ?? null) !== $persisted->capabilityVersion
                || ($approved['side_effect_level'] ?? null) !== $persisted->sideEffectLevel
                || !in_array($persisted->sideEffectLevel, $allowedSideEffects, true)) {
                throw new DomainException('Workflow immutable definition and persisted step binding differ.');
            }

            if ($persisted->state === 'completed') {
                $completed[] = $id;
                $completedSet[$id] = true;
            } elseif ($persisted->state === 'pending') {
                $pending[] = $id;
            } elseif (in_array($persisted->state, ['claimed', 'waiting', 'ambiguous'], true)) {
                if ($active !== null) {
                    throw new DomainException('Concurrent active or waiting Workflow steps are not supported.');
                }
                $active = ['state' => $persisted->state, 'step_id' => $id];
            } else {
                throw new DomainException('Unsupported or unsafe persisted Workflow step status.');
            }
        }
        if (count($byId) !== count($dependencies)) {
            throw new DomainException('Unexpected persisted Workflow step was inserted.');
        }
        foreach ($completed as $id) {
            foreach ($dependencies[$id] as $prerequisite) {
                if (!isset($completedSet[$prerequisite])) {
                    throw new DomainException('Completed Workflow step bypassed a prerequisite.');
                }
            }
        }
        if ($active !== null) {
            foreach ($dependencies[$active['step_id']] as $prerequisite) {
                if (!isset($completedSet[$prerequisite])) {
                    throw new DomainException('Claimed Workflow step bypassed a prerequisite.');
                }
            }
            return new WorkflowStepDecision(
                WorkflowStepSelectionState::from($active['state']), $active['step_id'], $completed);
        }
        foreach ($pending as $id) {
            $ready = true;
            foreach ($dependencies[$id] as $prerequisite) {
                if (!isset($completedSet[$prerequisite])) {
                    $ready = false;
                    break;
                }
            }
            if ($ready) {
                return new WorkflowStepDecision(WorkflowStepSelectionState::PENDING, $id, $completed);
            }
        }
        if ($pending !== []) {
            throw new DomainException('Workflow graph has pending steps without a runnable prerequisite.');
        }
        return new WorkflowStepDecision(WorkflowStepSelectionState::COMPLETE, null, $completed);
    }
}
