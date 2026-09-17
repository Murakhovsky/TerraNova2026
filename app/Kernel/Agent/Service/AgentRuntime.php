<?php
declare(strict_types=1);

namespace Kernel\Agent\Service;

use Kernel\Action\ActionProposal;
use Kernel\Agent\AgentDefinition;
use Kernel\Agent\AgentExecution;
use Kernel\Agent\AgentInvocation;
use Kernel\Agent\Contract\AgentAuditInterface;
use Kernel\Agent\Contract\AgentConfigurationProviderInterface;
use Kernel\Agent\Contract\AgentContextBuilderInterface;
use Kernel\Agent\Contract\AgentRunRepositoryInterface;
use Kernel\Agent\Contract\ContextRedactorInterface;
use Kernel\Agent\Contract\DecisionRepositoryInterface;
use Kernel\Agent\Contract\LlmClientInterface;
use Kernel\Agent\Contract\OrganizationAwareLlmClientInterface;
use Kernel\Agent\Model\AgentAuditEvent;
use RuntimeException;
use Throwable;

final readonly class AgentRuntime
{
    public function __construct(
        private AgentContextBuilderInterface $contexts,
        private LlmClientInterface $llm,
        private StructuredDecisionValidator $validator,
        private AgentRunRepositoryInterface $runs,
        private ?DecisionRepositoryInterface $decisions = null,
        private ?ContextRedactorInterface $redactor = null,
        private ?AgentConfigurationProviderInterface $configurations = null,
        private ?AgentAuditInterface $audit = null,
    ) {}

    public function run(AgentDefinition $agent, AgentInvocation $invocation): AgentExecution
    {
        if ($agent->configurationManaged) {
            if ($this->configurations === null) {
                throw new RuntimeException(sprintf(
                    'Agent %s requires managed configuration, but no configuration provider is available.',
                    $agent->name,
                ));
            }
            $agent = $this->configurations->effective($invocation->organizationId, $agent);
        }
        if (!$agent->enabled) {
            throw new RuntimeException(sprintf('Agent %s is disabled for this organization.', $agent->name));
        }

        $runId = bin2hex(random_bytes(16));
        $started = hrtime(true);
        $failureRecorded = false;
        $context = $this->contexts->build($invocation);
        $context = $this->selectContext($context, $agent->contextSources);
        $context = $this->redactor?->redact($context) ?? $context;
        $this->runs->start($runId, $agent, $invocation, $context);
        $this->audit?->record($runId, $agent, $invocation, AgentAuditEvent::REASONING_REQUEST, [
            'question' => $invocation->question,
            'context' => $context,
        ]);

        try {
            $response = $this->llm instanceof OrganizationAwareLlmClientInterface
                ? $this->llm->structuredForOrganization(
                    $invocation->organizationId,
                    $agent,
                    $invocation->question,
                    $context,
                    $runId,
                )
                : $this->llm->structured($agent, $invocation->question, $context);

            if ($this->redactor !== null) {
                $response = new \Kernel\Agent\LlmResponse(
                    $this->redactor->redact($response->output),
                    $response->provider,
                    $response->model,
                    $response->inputTokens,
                    $response->outputTokens,
                    $response->costAmount,
                    $response->costCurrency,
                );
            }
            $this->audit?->record(
                $runId,
                $agent,
                $invocation,
                AgentAuditEvent::REASONING_RESULT,
                [
                    'output' => $response->output,
                    'provider' => $response->provider,
                    'model' => $response->model,
                    'input_tokens' => $response->inputTokens,
                    'output_tokens' => $response->outputTokens,
                ],
                cost: $response->costAmount,
                costUnit: $response->costCurrency,
            );

            try {
                $result = $this->validator->validate($response->output, $agent);
                if ($agent->resultValidatorClass !== null) {
                    $validatorClass = $agent->resultValidatorClass;
                    if (!is_a($validatorClass, \Kernel\Agent\Contract\AgentResultValidatorInterface::class, true)) {
                        throw new RuntimeException('Configured Agent result validator does not implement the Kernel contract.');
                    }
                    (new $validatorClass())->validate($result, $agent);
                }
            } catch (Throwable $exception) {
                $duration = $this->duration($started);
                $this->runs->fail($runId, $exception, $duration, true);
                $this->audit?->record($runId, $agent, $invocation, AgentAuditEvent::FAILURE, [], $duration, error: $exception->getMessage());
                $failureRecorded = true;
                throw $exception;
            }

            $this->audit?->record($runId, $agent, $invocation, AgentAuditEvent::DECISION, [
                'decision' => $result->decision,
                'reason' => $result->reason,
                'confidence' => $result->confidence,
                'evidence' => $result->evidence,
                'proposed_actions' => $result->proposedActions,
            ]);
            $this->decisions?->save($runId, $agent, $invocation, $result);

            $proposals = [];
            if ($result->confidence >= $agent->confidenceThreshold) {
                foreach ($result->proposedActions as $index => $action) {
                    $targetType = $action['target_type'] ?? $invocation->subjectType;
                    $targetId = $action['target_id'] ?? $invocation->subjectId;
                    $proposal = new ActionProposal(
                        $action['type'],
                        $targetType,
                        $targetId,
                        $action['parameters'],
                        'AGENT',
                        $runId,
                        $agent->defaultExecutionMode,
                        $agent->defaultRiskLevel,
                        implode(':', [$runId, $index, $action['type'], $targetId]),
                    );
                    $proposals[] = $proposal;
                    $this->audit?->record($runId, $agent, $invocation, AgentAuditEvent::ACTION, [
                        'type' => $action['type'],
                        'target_type' => $targetType,
                        'target_id' => $targetId,
                        'parameters' => $action['parameters'],
                    ]);
                }
            }

            $duration = $this->duration($started);
            $this->runs->complete($runId, $result, $response, $duration);
            $this->audit?->record(
                $runId,
                $agent,
                $invocation,
                AgentAuditEvent::RESULT,
                ['decision' => $result->decision, 'confidence' => $result->confidence, 'proposal_count' => count($proposals)],
                $duration,
                $response->costAmount,
                $response->costCurrency,
            );
            return new AgentExecution($runId, $result, $proposals);
        } catch (Throwable $exception) {
            if (!$failureRecorded) {
                $duration = $this->duration($started);
                $this->runs->fail($runId, $exception, $duration);
                $this->audit?->record($runId, $agent, $invocation, AgentAuditEvent::FAILURE, [], $duration, error: $exception->getMessage());
            }
            throw $exception;
        }
    }

    /** @param list<string>|null $sources */
    private function selectContext(array $context, ?array $sources): array
    {
        if ($sources === null) return $context;
        $allowed = array_fill_keys(array_values(array_unique(array_map('strval', $sources))), true);
        foreach (['question', 'context_references', '_limits'] as $required) $allowed[$required] = true;
        return array_intersect_key($context, $allowed);
    }

    private function duration(int $started): int
    {
        return (int) round((hrtime(true) - $started) / 1_000_000);
    }
}
