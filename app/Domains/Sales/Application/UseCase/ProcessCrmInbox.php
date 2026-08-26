<?php
declare(strict_types=1);

namespace Domains\Sales\Application\UseCase;

use DateTimeImmutable;
use Domains\Sales\Application\Contract\CrmInboundApplierInterface;
use Domains\Sales\Application\Contract\CrmInboxRepositoryInterface;
use Kernel\Event\DomainEvent;
use Kernel\Event\EventBus;
use Kernel\Event\EventMetadata;
use Kernel\Module\DomainModuleRegistry;
use Kernel\Transaction\Contract\TransactionManagerInterface;
use RuntimeException;
use Throwable;

final readonly class ProcessCrmInbox
{
    public function __construct(
        private CrmInboxRepositoryInterface $inbox,
        private CrmInboundApplierInterface $applier,
        private DomainModuleRegistry $domains,
        private EventBus $events,
        private TransactionManagerInterface $transactions,
    ) {
    }

    public function execute(string $organizationId, string $inboxId, string $workerId): void
    {
        $item = $this->inbox->claim($organizationId, $inboxId, $workerId);
        if ($item === null) return;
        try {
            $this->transactions->transactional(function () use ($item): void {
                $applied = $this->applier->apply($item);
                if (!$this->domains->ownsEvent('sales', $applied['event_type'])) {
                    throw new RuntimeException('Mapped CRM event is not owned by Sales: ' . $applied['event_type']);
                }
                $eventId = bin2hex(random_bytes(16));
                $this->events->publish(new DomainEvent(
                    $eventId,
                    $item->organizationId,
                    $applied['event_type'],
                    $applied['aggregate_type'],
                    $applied['aggregate_id'],
                    $applied['payload'],
                    new EventMetadata($item->correlationId, null, 'INTEGRATION', $item->provider),
                    new DateTimeImmutable(),
                ));
                $this->inbox->complete($item);
            });
        } catch (Throwable $error) {
            $this->inbox->fail($item, $error);
            throw $error;
        }
    }
}
