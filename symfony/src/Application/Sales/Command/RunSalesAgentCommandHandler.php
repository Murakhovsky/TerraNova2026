<?php
declare(strict_types=1);

namespace App\Application\Sales\Command;

use Kernel\Agent\AgentInvocation;
use Kernel\Agent\Service\AgentRuntime;
use Kernel\Application\Command\CommandHandlerInterface;
use Kernel\Module\ActiveModuleResolver;
use Kernel\Module\DomainModuleRegistry;
use Kernel\Shared\Domain\OrganizationId;
use Kernel\Tool\Contract\ToolRuntimeInterface;
use Kernel\Tool\Model\ToolExecutionStatus;
use Kernel\Tool\Model\ToolInvocation;
use RuntimeException;

final readonly class RunSalesAgentCommandHandler implements CommandHandlerInterface
{
    public function __construct(
        private AgentRuntime $runtime,
        private DomainModuleRegistry $domains,
        private ActiveModuleResolver $modules,
        private ToolRuntimeInterface $tools,
    ) {
    }

    /** @return array{run_id:string,agent:string,proposal_count:int,tools:list<array<string,mixed>>} */
    public function __invoke(RunSalesAgentCommand $command): array
    {
        $agentName = trim($command->agentName);
        $subjectType = trim($command->subjectType);
        $subjectId = trim($command->subjectId);
        $question = trim($command->question);
        if ($agentName === '' || $subjectType === '' || $subjectId === '' || $question === '') {
            throw new RuntimeException('Sales agent command requires agent, subject, id and question.');
        }

        $moduleId = $this->domains->ownerOfAgent($agentName);
        if ($moduleId !== 'sales') {
            throw new RuntimeException('Wave 4 Symfony agent cutover accepts only Sales-owned agents.');
        }
        if (!$this->modules->isEnabled($command->organizationId, $moduleId)) {
            throw new RuntimeException(sprintf('Module %s is disabled for organization %s.', $moduleId, $command->organizationId));
        }

        $agent = $this->domains->agent($agentName);
        $execution = $this->runtime->run($agent, new AgentInvocation(
            $command->organizationId,
            $subjectType,
            $subjectId,
            $question,
            $command->correlationId,
            $command->contextReferences,
            $agentName,
        ));

        $toolResults = [];
        foreach ($execution->proposals as $index => $proposal) {
            $stableIdempotency = implode(':', [
                'agent-message',
                $command->idempotencyKey,
                (string) $index,
                $proposal->type,
                $proposal->targetId ?? 'none',
            ]);

            $toolExecution = $this->tools->execute(new ToolInvocation(
                OrganizationId::fromString($command->organizationId),
                'sales.action.propose',
                [
                    'agent_name' => $agentName,
                    'action_type' => $proposal->type,
                    'target_type' => $proposal->targetType ?? $subjectType,
                    'target_id' => $proposal->targetId ?? $subjectId,
                    'parameters' => $proposal->parameters,
                    'source_id' => $execution->runId,
                    'execution_mode' => $proposal->executionMode,
                    'risk_level' => $proposal->riskLevel,
                    'idempotency_key' => $stableIdempotency,
                ],
                $command->correlationId,
            ));

            if ($toolExecution->status() !== ToolExecutionStatus::COMPLETED || $toolExecution->result() === null) {
                throw new RuntimeException($toolExecution->error() ?? 'Sales action proposal tool did not complete.');
            }

            $toolResults[] = $toolExecution->result()->output();
        }

        return [
            'run_id' => $execution->runId,
            'agent' => $agentName,
            'proposal_count' => count($execution->proposals),
            'tools' => $toolResults,
        ];
    }
}
