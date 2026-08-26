<?php
declare(strict_types=1);

namespace Kernel\Queue\Handler;

use Kernel\Action\ActionStatus;
use Kernel\Action\ActionProposal;
use Kernel\Agent\AgentInvocation;
use Kernel\Agent\Service\AgentRuntime;
use Kernel\Policy\Service\ActionPolicyService;
use Kernel\Queue\Contract\JobHandlerInterface;
use Kernel\Queue\Contract\JobQueueInterface;
use Kernel\Queue\Job;
use Kernel\Module\DomainModuleRegistry;
use RuntimeException;

final readonly class AgentRunJobHandler implements JobHandlerInterface
{
    public const TYPE = 'AGENT_RUN';

    public function __construct(
        private AgentRuntime $runtime,
        private DomainModuleRegistry $domains,
        private ActionPolicyService $policies,
        private JobQueueInterface $queue,
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

        $agent = $this->domains->agent($agentName);
        $execution = $this->runtime->run($agent, new AgentInvocation(
            $job->organizationId,
            $subjectType,
            $subjectId,
            $question,
            $job->correlationId,
            is_array($job->payload['context_references'] ?? null) ? $job->payload['context_references'] : [],
            $agentName,
        ));

        foreach ($execution->proposals as $index => $proposal) {
            // The job identity, rather than the individual LLM run, makes retries idempotent.
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
    }
}
