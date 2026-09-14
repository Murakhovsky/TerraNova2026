<?php
declare(strict_types=1);

namespace Domains\Sales\Application\UseCase;

use Domains\Sales\Application\Contract\SalesActivityRepositoryInterface;
use Domains\Sales\Application\DTO\RecordCompletedCallCommand;
use Domains\Sales\Automation\Event\CallCompleted;
use Kernel\Event\EventBus;
use Kernel\Event\EventMetadata;
use Kernel\Transaction\Contract\TransactionManagerInterface;

final readonly class CompleteSalesCall
{
    public function __construct(
        private SalesActivityRepositoryInterface $activities,
        private EventBus $events,
        private TransactionManagerInterface $transactions,
    ) {
    }

    public function execute(RecordCompletedCallCommand $command): string
    {
        return $this->transactions->transactional(function () use ($command): string {
            $activityId = $this->activities->recordCompletedCall($command);
            $this->events->publish(CallCompleted::create(
                $command->eventId,
                $command->organizationId,
                $command->dealReference,
                max(0, $command->durationSeconds),
                $command->result,
                new EventMetadata(
                    $command->correlationId,
                    null,
                    $command->actorType,
                    $command->actorId,
                ),
                'client-case-activity:' . $activityId,
            ));
            return $activityId;
        });
    }
}
