<?php
declare(strict_types=1);

namespace Domains\Sales\Application\Service;

use DateTimeImmutable;
use Domains\Sales\Application\Contract\SalesAttentionRepositoryInterface;
use Domains\Sales\Application\Contract\SalesOutcomeRepositoryInterface;
use Domains\Sales\Application\DTO\RecordActionOutcomeCommand;
use Domains\Sales\Automation\Event\ActionOutcomeMeasured;
use Domains\Sales\Automation\Event\SalesEventType;
use Kernel\Event\DomainEvent;
use Kernel\Event\EventBus;
use Kernel\Event\EventMetadata;
use Kernel\Transaction\Contract\TransactionManagerInterface;

final readonly class SalesMonitoringService
{
    public function __construct(
        private SalesAttentionRepositoryInterface $attention,
        private SalesOutcomeRepositoryInterface $outcomes,
        private EventBus $events,
        private TransactionManagerInterface $transactions,
    ) {
    }

    public function detectNoActivity(
        string $organizationId,
        DateTimeImmutable $now,
        int $hours = 48,
        int $limit = 100,
    ): int {
        $cutoff = $now->modify('-' . max(1, $hours) . ' hours');
        $count = 0;

        foreach ($this->attention->inactiveDeals($organizationId, $cutoff, $limit) as $deal) {
            $this->transactions->transactional(function () use ($organizationId, $now, $hours, $deal, &$count): void {
                $window = $deal['id'] . ':' . $hours . ':' . ($deal['last_activity_at'] ?? 'never');
                $eventId = bin2hex(random_bytes(16));
                if (!$this->attention->claimSignal($organizationId, 'no_activity', $window, $eventId)) {
                    return;
                }

                $this->events->publish(new DomainEvent(
                    $eventId,
                    $organizationId,
                    SalesEventType::NO_ACTIVITY_DETECTED,
                    'deal',
                    (string) $deal['id'],
                    ['hours' => $hours, 'last_activity_at' => $deal['last_activity_at']],
                    new EventMetadata($eventId, null, 'SYSTEM', 'sales-no-activity-detector'),
                    $now,
                ));
                $count++;
            });
        }

        return $count;
    }

    public function detectMissedFollowups(
        string $organizationId,
        DateTimeImmutable $now,
        int $limit = 100,
    ): int {
        $count = 0;

        foreach ($this->attention->missedFollowups($organizationId, $now, $limit) as $followup) {
            $this->transactions->transactional(function () use ($organizationId, $now, $followup, &$count): void {
                $key = $followup['id'] . ':' . $followup['due_at'];
                $eventId = bin2hex(random_bytes(16));
                if (!$this->attention->claimSignal($organizationId, 'followup_missed', $key, $eventId)) {
                    return;
                }

                $this->events->publish(new DomainEvent(
                    $eventId,
                    $organizationId,
                    SalesEventType::FOLLOWUP_MISSED,
                    'deal',
                    (string) $followup['client_case_id'],
                    ['followup_id' => (string) $followup['id'], 'due_at' => $followup['due_at']],
                    new EventMetadata($eventId, null, 'SYSTEM', 'sales-followup-detector'),
                    $now,
                ));
                $count++;
            });
        }

        return $count;
    }

    public function recordOutcome(RecordActionOutcomeCommand $command): string
    {
        return $this->transactions->transactional(function () use ($command): string {
            $id = $this->outcomes->record($command);
            $this->events->publish(ActionOutcomeMeasured::create(bin2hex(random_bytes(16)), $id, $command));
            return $id;
        });
    }
}
