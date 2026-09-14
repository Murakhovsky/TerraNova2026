<?php
declare(strict_types=1);

namespace Domains\Sales\Automation\Action;

use Domains\Sales\Application\Service\SalesOperationService;
use Kernel\Action\Action;
use Kernel\Action\Contract\IdempotentExternalActionHandlerInterface;
use Kernel\Action\ExecutionResult;
use Kernel\Action\ExternalActionIdempotency;

final readonly class ScheduleMeetingHandler implements IdempotentExternalActionHandlerInterface
{
    public const TYPE = 'sales.schedule_meeting';

    public function __construct(private SalesOperationService $operations) {}
    public function supports(string $type): bool { return $type === self::TYPE; }
    public function idempotencyKey(Action $action): string { return ExternalActionIdempotency::resolve($action); }

    public function execute(Action $action): ExecutionResult
    {
        if ($action->targetType !== 'deal' || $action->targetId === null) return ExecutionResult::failure('Deal target is required.');
        try {
            $at = new \DateTimeImmutable((string) ($action->parameters['scheduled_at'] ?? $action->parameters['due_at'] ?? ''));
        } catch (\Throwable) {
            return ExecutionResult::failure('Valid scheduled_at is required.');
        }
        $result = $this->operations->scheduleMeeting(
            $action->organizationId, $action->targetId, (string) ($action->parameters['title'] ?? 'Sales meeting'),
            $at, $this->idempotencyKey($action),
            ['location' => $action->parameters['location'] ?? null, 'channel' => $action->parameters['channel'] ?? null, 'notes' => $action->parameters['notes'] ?? null],
        );
        return ExecutionResult::success(
            ['meeting_id' => $result->externalId, ...$result->data],
            ['meetings_scheduled' => ($result->data['duplicate'] ?? false) ? 0 : 1],
        );
    }
}
