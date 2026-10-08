<?php
declare(strict_types=1);

namespace App\Engineering\Application\Agent;

use App\Engineering\Domain\Agent\AgentRole;
use App\Engineering\Domain\Agent\EngineeringAgentTask;
use RuntimeException;
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
        if ($task->role === AgentRole::ENGINEERING_MANAGER) {
            throw new RuntimeException('Engineering Manager is a deterministic orchestration role in Runtime V2 and cannot execute Product work through the LLM runner.');
        }
        if ($task->role === AgentRole::QA) {
            throw new RuntimeException('Legacy QA role is read-only compatibility state and cannot start a new Engineering Runtime V2 execution.');
        }

        $lastError = null;
        $validationFeedback = null;
        $runCorrelationId = $this->runCorrelationId($correlationId, $task->id);

        for ($technicalRetry = 0; $technicalRetry <= $this->maxTechnicalRetries; ++$technicalRetry) {
            $result = $this->executeOnce($task, $organizationId, $runCorrelationId, $technicalRetry, $validationFeedback);

            if ($result->status !== AgentRunStatus::COMPLETED->value) {
                // Transport/provider resilience belongs to the LLM adapter. Re-running the
                // whole Engineering agent here multiplies provider retries, can hold a worker
                // for 10+ minutes and needlessly trips the cross-request circuit breaker.
                // Engineering-level retries are reserved for correcting invalid structured
                // output, where the model receives explicit validation feedback.
                throw new EngineeringAgentTechnicalFailureException(
                    $result->error ?? 'Engineering Agent runtime failed.',
                    $technicalRetry,
                );
            }

            try {
                $this->validator->validate($task->role, $result->structuredOutput);
                return $result;
            } catch (EngineeringAgentOutputValidationException $error) {
                $lastError = $error;
                $validationFeedback = 'Previous structured output was rejected: '.$error->getMessage().'. Return a corrected result that fully matches expected_output_schema.';
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

    private function runCorrelationId(string $parentCorrelationId, string $taskId): string
    {
        return mb_substr(rtrim($parentCorrelationId, ':').':agent:'.$taskId, 0, 128);
    }

    private function executeOnce(
        EngineeringAgentTask $task,
        string $organizationId,
        string $correlationId,
        int $technicalRetry,
        ?string $validationFeedback,
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
                'retry_correction' => $validationFeedback,
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
                'validation_feedback' => $validationFeedback,
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
            steps: array_map(
                static fn (\Kernel\Agent\Model\AgentStep $step): array => [
                    'id' => $step->id,
                    'sequence' => $step->sequence,
                    'type' => $step->type,
                    'status' => $step->status()->value,
                    'input' => $step->input,
                    'output' => $step->output(),
                    'error' => $step->error(),
                ],
                $run->steps(),
            ),
        );
    }
}
