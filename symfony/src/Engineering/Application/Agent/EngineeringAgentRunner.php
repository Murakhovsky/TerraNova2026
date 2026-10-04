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
        private EngineeringAgentOutputValidator $validator = new EngineeringAgentOutputValidator(),
        private int $maxTechnicalRetries = 2,
    ) {
    }

    public function run(EngineeringAgentTask $task, string $organizationId, string $correlationId): EngineeringAgentRunResult
    {
        $lastError = null;

        for ($technicalRetry = 0; $technicalRetry <= $this->maxTechnicalRetries; ++$technicalRetry) {
            $result = $this->executeOnce($task, $organizationId, $correlationId, $technicalRetry);

            if ($result->status !== AgentRunStatus::COMPLETED->value) {
                $lastError = new EngineeringAgentTechnicalFailureException(
                    $result->error ?? 'Engineering Agent runtime failed.',
                    $technicalRetry,
                );
                if ($technicalRetry < $this->maxTechnicalRetries) continue;
                throw $lastError;
            }

            try {
                $this->validator->validate($task->role, $result->structuredOutput);
                return $result;
            } catch (EngineeringAgentOutputValidationException $error) {
                $lastError = $error;
                if ($technicalRetry < $this->maxTechnicalRetries) continue;

                throw new EngineeringAgentTechnicalFailureException(
                    'Engineering Agent returned invalid structured output after technical retries: '.$error->getMessage(),
                    $technicalRetry,
                    $error,
                );
            }
        }

        throw new EngineeringAgentTechnicalFailureException(
            $lastError?->getMessage() ?? 'Engineering Agent technical retry loop exhausted.',
            $this->maxTechnicalRetries,
            $lastError,
        );
    }

    private function executeOnce(
        EngineeringAgentTask $task,
        string $organizationId,
        string $correlationId,
        int $technicalRetry,
    ): EngineeringAgentRunResult {
        $definition = $this->definitions->create($task->role, $organizationId);
        $agent = new Agent(strtolower($task->role->value), $definition, tags: ['engineering']);
        $organization = OrganizationId::fromString($organizationId);
        $instance = new AgentInstance($task->id, $organization, $agent, [
            'feature_id' => $task->featureId,
            'idempotency_key' => $task->idempotencyKey,
            'technical_retry' => $technicalRetry,
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
                'technical_retry' => $technicalRetry,
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
            technicalRetries: $technicalRetry,
        );
    }
}
