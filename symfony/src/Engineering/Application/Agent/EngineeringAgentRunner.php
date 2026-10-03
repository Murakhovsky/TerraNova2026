<?php
declare(strict_types=1);

namespace App\Engineering\Application\Agent;

use App\Engineering\Domain\Agent\EngineeringAgentTask;
use Kernel\Agent\Contract\AgentRuntimeInterface;
use Kernel\Agent\Model\Agent;
use Kernel\Agent\Model\AgentContext;
use Kernel\Agent\Model\AgentInstance;
use Kernel\Agent\Model\AgentRunStatus;
use Kernel\Shared\Domain\OrganizationId;

final readonly class EngineeringAgentRunner implements EngineeringAgentRunnerInterface
{
    public function __construct(
        private AgentRuntimeInterface $runtime,
        private EngineeringAgentDefinitionFactory $definitions = new EngineeringAgentDefinitionFactory(),
    ) {
    }

    public function run(EngineeringAgentTask $task, string $organizationId, string $correlationId): EngineeringAgentRunResult
    {
        $definition = $this->definitions->create($task->role);
        $agent = new Agent(strtolower($task->role->value), $definition, tags: ['engineering']);
        $organization = OrganizationId::fromString($organizationId);
        $instance = new AgentInstance($task->id, $organization, $agent, [
            'feature_id' => $task->featureId,
            'idempotency_key' => $task->idempotencyKey,
        ]);
        $context = new AgentContext(
            organizationId: $organization,
            correlationId: $correlationId,
            input: [
                'question' => $task->objective,
                'inputs' => $task->inputs,
                'constraints' => $task->constraints,
                'completion_criteria' => $task->completionCriteria,
                'expected_output_schema' => $task->expectedOutputSchema,
            ],
            data: [
                'context_refs' => $task->contextRefs,
                'input_snapshot' => $task->inputSnapshot,
            ],
            metadata: [
                'engineering_feature_id' => $task->featureId,
                'engineering_task_id' => $task->id,
                'engineering_role' => $task->role->value,
                'idempotency_key' => $task->idempotencyKey,
            ],
        );

        $run = $this->runtime->execute($instance, $context);
        $output = $run->output();

        return new EngineeringAgentRunResult(
            runId: $run->id,
            role: $task->role,
            status: $run->status()->value,
            structuredOutput: $output?->structured ?? [],
            provider: $output?->provider,
            model: $output?->model,
            usage: $output?->usage ?? [],
            error: $run->status() === AgentRunStatus::FAILED ? $run->error() : null,
        );
    }
}
