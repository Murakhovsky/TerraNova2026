<?php
declare(strict_types=1);

namespace Domains\Sales\Application\UseCase;

use Domains\Sales\Application\Contract\FollowupRepositoryInterface;
use Domains\Sales\Application\DTO\OperationResult;
use Domains\Sales\Application\DTO\ScheduleFollowupCommand;
use Domains\Sales\Automation\Event\SalesEventType;
use Kernel\Event\DomainEvent;
use Kernel\Event\EventBus;
use Kernel\Event\EventMetadata;
use Kernel\Transaction\Contract\TransactionManagerInterface;

final readonly class ScheduleDealFollowup
{
    public function __construct(
        private FollowupRepositoryInterface $followups,
        private EventBus $events,
        private TransactionManagerInterface $transactions,
    ) {
    }

    public function execute(
        ScheduleFollowupCommand $command,
        string $correlationId,
        string $actorType = 'SYSTEM',
        string $actorId = 'system',
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
                SalesEventType::FOLLOWUP_CREATED,
                'deal',
                $command->dealReference,
                [
                    'followup_id' => $result->externalId,
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
