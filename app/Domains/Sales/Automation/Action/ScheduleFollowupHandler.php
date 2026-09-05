<?php
declare(strict_types=1);

namespace Domains\Sales\Automation\Action;

use DateTimeImmutable;
use Domains\Sales\Application\Contract\FollowupRepositoryInterface;
use Domains\Sales\Application\DTO\ScheduleFollowupCommand;
use Kernel\Action\Action;
use Kernel\Action\Contract\ActionHandlerInterface;
use Kernel\Action\ExecutionResult;

final readonly class ScheduleFollowupHandler implements ActionHandlerInterface
{
    public const TYPES = ['sales.schedule_followup', 'sales.create_followup'];

    public function __construct(private FollowupRepositoryInterface $followups)
    {
    }

    public function supports(string $actionType): bool
    {
        return in_array($actionType, self::TYPES, true);
    }

    public function execute(Action $action): ExecutionResult
    {
        if ($action->targetType !== 'deal' || $action->targetId === null) {
            return ExecutionResult::failure('Deal target is required.');
        }
        $dueAt = isset($action->parameters['due_at'])
            ? new DateTimeImmutable((string) $action->parameters['due_at'])
            : (new DateTimeImmutable())->modify('+' . max(1, (int) ($action->parameters['due_in_minutes'] ?? 1440)) . ' minutes');

        $result = $this->followups->schedule(new ScheduleFollowupCommand(
            $action->organizationId,
            $action->targetId,
            (string) ($action->parameters['title'] ?? 'Follow-up'),
            isset($action->parameters['body']) ? (string) $action->parameters['body'] : null,
            $dueAt,
            $action->idempotencyKey ?? $action->id,
        ));

        return $result->successful
            ? ExecutionResult::success(['followup_id' => $result->externalId, 'due_at' => $dueAt->format(DATE_ATOM), ...$result->data], ['followups_scheduled' => 1])
            : ExecutionResult::failure($result->error ?? 'Follow-up scheduling failed.', $result->data);
    }
}
