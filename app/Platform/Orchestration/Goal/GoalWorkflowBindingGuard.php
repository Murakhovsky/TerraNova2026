<?php
declare(strict_types=1);

namespace Platform\Orchestration\Goal;

use DomainException;
use Kernel\Workflow\Model\Step\AgentStep;
use Kernel\Workflow\Model\Step\HumanStep;
use Kernel\Workflow\Model\Step\SystemStep;
use Kernel\Workflow\Model\Step\ToolStep;
use Kernel\Workflow\Model\WorkflowInstance;

/**
 * Fail-closed preflight before a federated plan can be bound to an existing Workflow.
 * Plan metadata alone never grants authorization to run an Agent, Tool or System step.
 */
final readonly class GoalWorkflowBindingGuard
{
    /**
     * @param list<array<string,mixed>> $planSteps Persisted canonical, validated plan snapshot
     * @param list<string> $approvedCapabilityIds Resolved independently from trusted Policy/Approval runtime
     */
    public function assertCompatible(
        GoalSpecification $goal,
        array $planSteps,
        WorkflowInstance $workflow,
        array $approvedCapabilityIds,
    ): void {
        if ($workflow->organizationId->value() !== $goal->organizationId || !$workflow->enabled) {
            throw new DomainException('Workflow does not belong to this Goal tenant or is disabled.');
        }
        if ($planSteps === [] || !array_is_list($planSteps)) {
            throw new DomainException('Cannot bind an empty or malformed Goal plan.');
        }
        $definition = $workflow->workflow->definition;
        if (count($definition->steps) !== count($planSteps)) {
            throw new DomainException('Workflow topology differs from the approved plan snapshot.');
        }
        $mapped = [];
        foreach ($planSteps as $step) {
            $id = $step['id'] ?? null;
            $capability = $step['capability_id'] ?? null;
            $version = $step['capability_version'] ?? null;
            if (!is_string($id) || !is_string($capability) || !is_string($version)
                || $id === '' || $capability === '' || $version === '' || isset($mapped[$id])) {
                throw new DomainException('Invalid or duplicate federated plan step.');
            }
            if (!in_array($capability, $goal->allowedCapabilities, true)
                || !in_array($capability, $approvedCapabilityIds, true)) {
                throw new DomainException('Capability has not been independently authorized for Goal execution: ' . $capability);
            }
            $mapped[$id] = $step;
        }
        foreach ($definition->steps as $actual) {
            $step = $mapped[$actual->id] ?? null;
            if ($step === null) {
                throw new DomainException('Workflow step not declared in approved snapshot: ' . $actual->id);
            }
            if ($actual instanceof SystemStep) {
                // The only System operation admitted here is a literal,
                // zero-input, side-effect-free checkpoint marker.
                if ($actual->operation !== 'federation.read_only.checkpoint'
                    || $actual->payload !== []
                    || ($step['side_effect_level'] ?? null) !== 'none') {
                    throw new DomainException('System execution requires verified domain Action/Policy adapter.');
                }
            }
            if ($actual instanceof ToolStep || $actual instanceof AgentStep) {
                // No production binding registry verified these handlers or their side effects yet.
                throw new DomainException('Executable workflow steps require a verified binding and action-policy adapter.');
            }
            if ($actual instanceof HumanStep && ($step['side_effect_level'] ?? null) !== 'none') {
                throw new DomainException('Human gate cannot authorize an undeclared side effect.');
            }
            if (($step['side_effect_level'] ?? null) !== 'none') {
                throw new DomainException('Side effects must be mediated by canonical Action/Approval services.');
            }
        }
    }
}
