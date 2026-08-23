<?php
declare(strict_types=1);

namespace Kernel\Agent\Service;

use Kernel\Action\ActionProposal;
use Kernel\Agent\AgentDefinition;
use Kernel\Agent\AgentExecution;
use Kernel\Agent\AgentInvocation;
use Kernel\Agent\Contract\AgentContextBuilderInterface;
use Kernel\Agent\Contract\AgentRunRepositoryInterface;
use Kernel\Agent\Contract\LlmClientInterface;
use Kernel\Agent\Contract\DecisionRepositoryInterface;
use Throwable;

final readonly class AgentRuntime
{
    public function __construct(
        private AgentContextBuilderInterface $contexts,
        private LlmClientInterface $llm,
        private StructuredDecisionValidator $validator,
        private AgentRunRepositoryInterface $runs,
        private ?DecisionRepositoryInterface $decisions = null,
    ) {}

    public function run(AgentDefinition $agent, AgentInvocation $invocation): AgentExecution
    {
        $runId = bin2hex(random_bytes(16));
        $started = hrtime(true);
        $failureRecorded = false;
        $context = $this->contexts->build($invocation);
        $this->runs->start($runId, $agent, $invocation, $context);

        try {
            $response = $this->llm->structured($agent, $invocation->question, $context);
            try {
                $result = $this->validator->validate($response->output, $agent);
            } catch (Throwable $exception) {
                $this->runs->fail($runId, $exception, $this->duration($started), true);
                $failureRecorded = true;
                throw $exception;
            }

            $this->decisions?->save($runId, $agent, $invocation, $result);

            $proposals = [];
            foreach ($result->proposedActions as $index => $action) {
                $targetType = $action['target_type'] ?? $invocation->subjectType;
                $targetId = $action['target_id'] ?? $invocation->subjectId;
                $proposals[] = new ActionProposal(
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
            }

            $this->runs->complete($runId, $result, $response, $this->duration($started));
            return new AgentExecution($runId, $result, $proposals);
        } catch (Throwable $exception) {
            if (!$failureRecorded) {
                $this->runs->fail($runId, $exception, $this->duration($started));
            }
            throw $exception;
        }
    }

    private function duration(int $started): int
    {
        return (int) round((hrtime(true) - $started) / 1_000_000);
    }
}
