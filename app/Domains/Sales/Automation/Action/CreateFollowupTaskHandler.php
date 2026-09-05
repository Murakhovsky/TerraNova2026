<?php
declare(strict_types=1);

namespace Domains\Sales\Automation\Action;

use DateTimeImmutable;
use Domains\Sales\Application\Contract\CrmGatewayInterface;
use Domains\Sales\Application\DTO\CreateTaskCommand;
use Kernel\Action\Action;
use Kernel\Action\Contract\ActionHandlerInterface;
use Kernel\Action\ExecutionResult;

final readonly class CreateFollowupTaskHandler implements ActionHandlerInterface
{
    public const TYPES = [
        'sales.create_task',
        'sales.create_qualification_task',
        'sales.create_followup_task',
        'sales.escalate_overdue_followup',
        'sales.request_manager_review',
        'sales.request_document',
        'sales.schedule_meeting',
    ];

    public function __construct(private CrmGatewayInterface $crm)
    {
    }

    public function supports(string $actionType): bool
    {
        return in_array($actionType, self::TYPES, true);
    }

    public function execute(Action $action): ExecutionResult
    {
        if ($action->targetType !== 'deal' || $action->targetId === null) {
            return ExecutionResult::failure('Sales task action requires a deal target.');
        }

        $dueAt = isset($action->parameters['due_at'])
            ? new DateTimeImmutable((string) $action->parameters['due_at'])
            : null;
        if ($dueAt === null && isset($action->parameters['due_in_minutes'])) {
            $dueAt = (new DateTimeImmutable())->modify(sprintf('+%d minutes', max(1, (int) $action->parameters['due_in_minutes'])));
        }

        $result = $this->crm->createTask(new CreateTaskCommand(
            $action->organizationId,
            $action->targetId,
            (string) ($action->parameters['title'] ?? 'Follow-up'),
            isset($action->parameters['body']) ? (string) $action->parameters['body'] : null,
            $dueAt,
            $action->idempotencyKey ?? $action->id,
        ));

        return $result->successful
            ? ExecutionResult::success(['external_id' => $result->externalId, ...$result->data], ['tasks_created' => 1])
            : ExecutionResult::failure($result->error ?? 'CRM task creation failed.', $result->data);
    }
}
