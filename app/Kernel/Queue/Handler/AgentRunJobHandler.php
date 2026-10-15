<?php
declare(strict_types=1);

namespace Kernel\Queue\Handler;

use Kernel\Action\ActionStatus;
use Kernel\Action\ActionProposal;
use Kernel\Agent\AgentInvocation;
use Kernel\Agent\Service\AgentRuntime;
use Kernel\Module\ActiveModuleResolver;
use Kernel\Module\DomainModuleRegistry;
use Kernel\Policy\Service\ActionPolicyService;
use Kernel\Queue\Contract\JobHandlerInterface;
use Kernel\Queue\Contract\JobQueueInterface;
use Kernel\Queue\Job;
use RuntimeException;
use Throwable;
use Kernel\Execution\Contract\KernelCanonicalExecutionFenceInterface;

final readonly class AgentRunJobHandler implements JobHandlerInterface
{
    public const TYPE = 'AGENT_RUN';

    public function __construct(
        private AgentRuntime $runtime,
        private DomainModuleRegistry $domains,
        private ActionPolicyService $policies,
        private JobQueueInterface $queue,
        private ?ActiveModuleResolver $modules = null,
        private ?KernelCanonicalExecutionFenceInterface $canonical = null,
    ) {}

    public function supports(string $type): bool
    {
        return $type === self::TYPE;
    }

    public function handle(Job $job): void
    {
        $subjectType = (string) ($job->payload['subject_type'] ?? '');
        $subjectId = (string) ($job->payload['subject_id'] ?? '');
        $question = (string) ($job->payload['question'] ?? '');
        $agentName = (string) ($job->payload['agent_name'] ?? '');
        if ($agentName === '' || $subjectType === '' || $subjectId === '' || $question === '') {
            throw new RuntimeException('AGENT_RUN job requires agent_name, subject_type, subject_id and question.');
        }

        $moduleId = $this->domains->ownerOfAgent($agentName);
        if ($moduleId !== null && $this->modules !== null && !$this->modules->isEnabled($job->organizationId, $moduleId)) {
            return;
        }

        $agent = $this->domains->agent($agentName);
        $claim = $this->canonical?->beginAgent($job, $agentName);
        try {
            $execution = $this->runtime->run($agent, new AgentInvocation(
                $job->organizationId,
                $subjectType,
                $subjectId,
                $question,
                $job->correlationId,
                is_array($job->payload['context_references'] ?? null) ? $job->payload['context_references'] : [],
                $agentName,
                $claim !== null ? $job->id : null,
                $claim?->token,
            ));

            // Native reasoning result alone is not the complete queued job.
            // Publish Action proposals under job-stable idempotency keys first.
            // If publication fails after any side effect, quarantine for operator
            // reconciliation rather than completing the technical Run prematurely.
            foreach ($execution->proposals as $index => $proposal) {
                $stableProposal = new ActionProposal(
                    $proposal->type,
                    $proposal->targetType,
                    $proposal->targetId,
                    $proposal->parameters,
                    $proposal->sourceType,
                    $proposal->sourceId,
                    $proposal->executionMode,
                    $proposal->riskLevel,
                    implode(':', ['agent-job', $job->id, $index, $proposal->type, $proposal->targetId ?? 'none']),
                );
                $action = $this->policies->submit($job->organizationId, $stableProposal, $job->correlationId);
                if ($action->status === ActionStatus::Queued) {
                    $this->queue->enqueue(
                        $job->organizationId,
                        ActionExecutionJobHandler::TYPE,
                        ['action_id' => $action->id],
                        $job->correlationId,
                        'action-execution:' . $action->id,
                        5,
                        120,
                    );
                }
            }

            // Only now has the Agent job reached its local hand-off boundary.
            // This is not proof that downstream Actions achieved business results.
            if ($claim !== null) {
                $this->canonical->completeAgent($claim, $execution->runId);
            }
        } catch (Throwable $error) {
            if ($claim !== null) {
                try {
                    $this->canonical->quarantine($claim);
                } catch (Throwable) {
                    // An expired/completed claim still blocks replay. The exact
                    // persisted outcome requires independent operator recovery.
                }
            }
            throw $error;
        }
    }
}
