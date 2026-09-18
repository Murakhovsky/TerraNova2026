<?php
declare(strict_types=1);

namespace App\Infrastructure\Automation;

use App\Application\System\Command\ExecuteSalesActionCommand;
use Kernel\Action\ActionProposal;
use Kernel\Action\ActionStatus;
use Kernel\Application\Bus\CommandBusInterface;
use Kernel\Policy\Service\ActionPolicyService;
use Kernel\Tool\Contract\ToolInterface;
use Kernel\Tool\Model\ToolDefinition;
use Kernel\Tool\Model\ToolEffect;
use Kernel\Tool\Model\ToolInvocation;
use Kernel\Tool\Model\ToolResult;

final readonly class SalesActionProposalTool implements ToolInterface
{
    public function __construct(
        private ActionPolicyService $policies,
        private CommandBusInterface $commands,
    ) {
    }

    public function definition(): ToolDefinition
    {
        return new ToolDefinition(
            'sales.action.propose',
            'Submit one Agent-proposed Sales action through canonical Policy/Action governance.',
            [
                'type' => 'object',
                'required' => [
                    'agent_name', 'action_type', 'target_type', 'target_id', 'parameters',
                    'source_id', 'execution_mode', 'risk_level', 'idempotency_key',
                ],
                'properties' => [
                    'agent_name' => ['type' => 'string'],
                    'action_type' => ['type' => 'string'],
                    'target_type' => ['type' => 'string'],
                    'target_id' => ['type' => 'string'],
                    'parameters' => ['type' => 'object'],
                    'source_id' => ['type' => 'string'],
                    'execution_mode' => ['type' => 'string'],
                    'risk_level' => ['type' => 'string'],
                    'idempotency_key' => ['type' => 'string'],
                ],
            ],
            [
                'type' => 'object',
                'properties' => [
                    'action_id' => ['type' => 'string'],
                    'status' => ['type' => 'string'],
                    'queued' => ['type' => 'boolean'],
                ],
            ],
            ToolEffect::WRITE,
        );
    }

    public function invoke(ToolInvocation $invocation): ToolResult
    {
        $input = $invocation->input();
        $proposal = new ActionProposal(
            type: trim((string) $input['action_type']),
            targetType: trim((string) $input['target_type']),
            targetId: trim((string) $input['target_id']),
            parameters: is_array($input['parameters']) ? $input['parameters'] : [],
            sourceType: 'AGENT',
            sourceId: trim((string) $input['source_id']),
            executionMode: strtoupper(trim((string) $input['execution_mode'])),
            riskLevel: strtoupper(trim((string) $input['risk_level'])),
            idempotencyKey: trim((string) $input['idempotency_key']),
            policyContext: [
                'actor' => ['role' => 'AGENT'],
                'agent' => ['name' => trim((string) $input['agent_name'])],
            ],
        );

        $action = $this->policies->submit(
            $invocation->organizationId()->value(),
            $proposal,
            $invocation->correlationId(),
        );

        if ($action->status === ActionStatus::Queued) {
            $this->commands->dispatch(new ExecuteSalesActionCommand(
                $action->organizationId,
                $action->id,
            ));
        }

        return ToolResult::success([
            'action_id' => $action->id,
            'status' => $action->status->value,
            'queued' => $action->status === ActionStatus::Queued,
        ], [
            'governed' => true,
            'source_type' => 'AGENT',
        ]);
    }
}
