<?php
declare(strict_types=1);

namespace App\Engineering\Application\Agent;

use App\Engineering\Application\DomainDevelopment\EngineeringFeatureBudgetGuard;
use App\Engineering\Application\Policy\AgentCapabilityRegistry;
use App\Engineering\Application\Policy\RuntimeCapabilityRegistry;
use App\Engineering\Application\Security\EngineeringSecretIsolationGuard;
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
        private EngineeringSecretIsolationGuard $secrets = new EngineeringSecretIsolationGuard(),
        private AgentCapabilityRegistry $agentCapabilities = new AgentCapabilityRegistry(),
        private RuntimeCapabilityRegistry $runtimeCapabilities = new RuntimeCapabilityRegistry(),
        private ?EngineeringFeatureBudgetGuard $resourceBudgets = null,
        private int $maxTechnicalRetries = 2,
    ) {
    }

    public function run(EngineeringAgentTask $task, string $organizationId, string $correlationId): EngineeringAgentRunResult
    {
        $lastError = null;
        $runCorrelationId = $this->runCorrelationId($correlationId, $task->id);

        for ($technicalRetry = 0; $technicalRetry <= $this->maxTechnicalRetries; ++$technicalRetry) {
            $result = $this->executeOnce($task, $organizationId, $runCorrelationId, $technicalRetry);

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

    private function runCorrelationId(string $parentCorrelationId, string $taskId): string
    {
        return mb_substr(rtrim($parentCorrelationId, ':').':agent:'.$taskId, 0, 128);
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
        $input = $this->secrets->sanitize([
            'question' => $task->objective,
            'inputs' => $task->inputs,
            'constraints' => $task->constraints,
            'completion_criteria' => $task->completionCriteria,
            'expected_output_schema' => $task->expectedOutputSchema,
        ]);
        $data = $this->secrets->sanitize([
            'context_refs' => $task->contextRefs,
            'input_snapshot' => $task->inputSnapshot,
            'agent_capabilities' => $this->agentCapabilities->forRole($task->role),
            'runtime_capabilities' => $this->runtimeCapabilities->forRuntime('EngineeringRuntime'),
        ]);

        $budgetEvidence = [];
        if ($this->resourceBudgets !== null) {
            $decision = $this->resourceBudgets->decision($task->featureId);
            if (($decision['allowed'] ?? false) !== true) {
                throw new \RuntimeException('Engineering Feature resource budget exhausted: '.implode('; ', $decision['reasons'] ?? []));
            }
            $budgetEvidence = is_array($decision['evidence'] ?? null) ? $decision['evidence'] : [];
            $contextBytes = strlen(json_encode(
                ['input' => $input, 'data' => $data],
                JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES,
            ));
            $contextBudget = max(1000, (int) ($budgetEvidence['context_budget'] ?? 120000));
            if ($contextBytes > $contextBudget) {
                throw new \RuntimeException(sprintf('Engineering Agent context budget exceeded: %d > %d bytes.', $contextBytes, $contextBudget));
            }
            $estimatedInputTokens = max(1, (int) ceil($contextBytes / 4));
            $runTokenBudget = max(1, (int) ($budgetEvidence['agent_run_token_budget'] ?? 4000));
            if ($estimatedInputTokens >= $runTokenBudget) {
                throw new \RuntimeException(sprintf(
                    'Engineering Agent estimated input token budget exhausted before provider call: %d >= %d.',
                    $estimatedInputTokens,
                    $runTokenBudget,
                ));
            }
            $budgetEvidence['context_bytes'] = $contextBytes;
            $budgetEvidence['estimated_input_tokens'] = $estimatedInputTokens;
            $budgetEvidence['max_output_tokens'] = $runTokenBudget - $estimatedInputTokens;
        }

        $context = new AgentContext(
            organizationId: $organization,
            correlationId: $correlationId,
            input: $input,
            data: $data,
            metadata: [
                'engineering_feature_id' => $task->featureId,
                'engineering_task_id' => $task->id,
                'engineering_role' => $task->role->value,
                'idempotency_key' => $task->idempotencyKey,
                'technical_retry' => $technicalRetry,
                'resource_budget' => $budgetEvidence,
                'llm_max_output_tokens' => isset($budgetEvidence['max_output_tokens'])
                    ? max(1, (int) $budgetEvidence['max_output_tokens'])
                    : null,
                'llm_max_cost_amount' => isset($budgetEvidence['agent_run_cost_budget'])
                    ? max(0.000001, (float) $budgetEvidence['agent_run_cost_budget'])
                    : null,
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
