<?php
declare(strict_types=1);

namespace Domains\Sales\Application\UseCase;

use Domains\Sales\Application\Contract\LeadFollowupRepositoryInterface;
use Domains\Sales\Application\DTO\OperationResult;
use Domains\Sales\Application\DTO\ScheduleLeadFollowupCommand;
use Domains\Sales\Automation\Event\SalesEventType;
use Kernel\Event\DomainEvent;
use Kernel\Event\EventBus;
use Kernel\Event\EventMetadata;
use Kernel\Transaction\Contract\TransactionManagerInterface;

final readonly class ScheduleLeadFollowup
{
    public function __construct(
        private LeadFollowupRepositoryInterface $followups,
        private EventBus $events,
        private TransactionManagerInterface $transactions,
    ) {
    }

    public function execute(
        ScheduleLeadFollowupCommand $command,
        string $correlationId,
        string $actorType = 'SYSTEM',
        string $actorId = 'sales-automation',
    ): OperationResult {
        return $this->transactions->transactional(function () use ($command, $correlationId, $actorType, $actorId): OperationResult {
            $result = $this->followups->schedule($command);
            if (!$result->successful || ($result->data['duplicate'] ?? false) === true) {
                return $result;
            }

            $eventId = bin2hex(random_bytes(16));
            $this->events->publish(new DomainEvent(
                $eventId,
                $command->organizationId,
                SalesEventType::TASK_CREATED,
                'lead',
                $command->leadReference,
                [
                    'activity_id' => $result->externalId,
                    'activity_type' => 'task',
                    'purpose' => 'lead_followup',
                    'title' => $command->title,
                    'due_at' => $command->dueAt->format(DATE_ATOM),
                ],
                new EventMetadata($correlationId !== '' ? $correlationId : $eventId, null, $actorType, $actorId),
                new \DateTimeImmutable(),
            ));

            return $result;
        });
    }
}
