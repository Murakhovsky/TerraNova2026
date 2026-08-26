<?php
declare(strict_types=1);

namespace Kernel\Event\Service;

use Kernel\Event\Contract\EventConsumptionRepositoryInterface;
use Kernel\Event\Contract\EventOutboxInterface;
use Kernel\Transaction\Contract\TransactionManagerInterface;

final readonly class OutboxReplayService
{
    public function __construct(
        private EventOutboxInterface $outbox,
        private EventConsumptionRepositoryInterface $consumptions,
        private ?TransactionManagerInterface $transactions = null,
    ) {
    }

    /** @return array{outbox: int, consumptions: int} */
    public function replay(?string $organizationId = null, ?string $eventId = null): array
    {
        $operation = function () use ($organizationId, $eventId): array {
            $consumptions = $this->consumptions->reset($organizationId, $eventId);
            $outbox = $this->outbox->replay($organizationId, $eventId);
            return ['outbox' => $outbox, 'consumptions' => $consumptions];
        };
        return $this->transactions?->transactional($operation) ?? $operation();
    }
}
