<?php
declare(strict_types=1);

namespace Domains\Sales\Automation\Action;

use DateTimeImmutable;
use Domains\Sales\Application\DTO\ScheduleLeadFollowupCommand;
use Domains\Sales\Application\UseCase\ScheduleLeadFollowup;
use Kernel\Action\Action;
use Kernel\Action\Contract\ActionHandlerInterface;
use Kernel\Action\ExecutionResult;

final readonly class CreateLeadFollowupTaskHandler implements ActionHandlerInterface
{
    public const TYPE = 'sales.create_lead_followup_task';

    public function __construct(private ScheduleLeadFollowup $followups)
    {
    }

    public function supports(string $actionType): bool
    {
        return $actionType === self::TYPE;
    }

    public function execute(Action $action): ExecutionResult
    {
        if ($action->targetType !== 'lead' || $action->targetId === null) {
            return ExecutionResult::failure('Lead target is required.');
        }

        $dueAt = isset($action->parameters['due_at'])
            ? new DateTimeImmutable((string) $action->parameters['due_at'])
            : (new DateTimeImmutable())->modify('+' . max(1, (int) ($action->parameters['due_in_minutes'] ?? 30)) . ' minutes');

        $result = $this->followups->execute(
            new ScheduleLeadFollowupCommand(
                $action->organizationId,
                $action->targetId,
                (string) ($action->parameters['title'] ?? 'Contact new lead'),
                isset($action->parameters['body']) ? (string) $action->parameters['body'] : null,
                $dueAt,
                $action->idempotencyKey ?? $action->id,
            ),
            $action->correlationId !== '' ? $action->correlationId : $action->id,
            strtoupper($action->sourceType ?: 'SYSTEM'),
            $action->sourceId ?: 'sales-automation',
        );

        return $result->successful
            ? ExecutionResult::success(
                ['activity_id' => $result->externalId, 'due_at' => $dueAt->format(DATE_ATOM), ...$result->data],
                ['lead_followups_created' => ($result->data['duplicate'] ?? false) ? 0 : 1],
            )
            : ExecutionResult::failure($result->error ?? 'Lead follow-up scheduling failed.', $result->data);
    }
}
