<?php
declare(strict_types=1);

namespace Kernel\Event\Service;

use Kernel\Event\Contract\EventOutboxInterface;
use Kernel\Event\Contract\EventStoreInterface;
use Kernel\Observability\StructuredLoggerInterface;
use Kernel\Operations\Contract\MetricsRecorderInterface;
use RuntimeException;
use Throwable;

final readonly class OutboxPublisher
{
    public function __construct(
        private EventOutboxInterface $outbox,
        private EventStoreInterface $events,
        private DurableEventDispatcher $dispatcher,
        private ?MetricsRecorderInterface $metrics = null,
        private ?StructuredLoggerInterface $logger = null,
    ) {
    }

    public function runOne(string $workerId): bool
    {
        $this->outbox->recoverTimedOut();
        $message = $this->outbox->claim($workerId);
        if ($message === null) {
            return false;
        }

        try {
            $event = $this->events->find($message->eventId)
                ?? throw new RuntimeException('Outbox event does not exist: ' . $message->eventId);
            $this->dispatcher->dispatch($event);
            $this->outbox->markPublished($message);
            $this->observe('cos.outbox.published', $message->organizationId);
        } catch (Throwable $error) {
            $this->outbox->markFailed($message, $error);
            $this->observe('cos.outbox.failed', $message->organizationId, $error);
        }

        return true;
    }

    private function observe(string $metric, string $organizationId, ?Throwable $error = null): void
    {
        try {
            $this->metrics?->record($metric, 1, $organizationId);
            if ($error !== null) {
                $this->logger?->log('error', 'Durable event delivery failed.', [
                    'organization_id' => $organizationId,
                    'exception' => $error::class,
                    'error' => $error->getMessage(),
                ]);
            }
        } catch (Throwable) {
            // Telemetry is best-effort and must not alter delivery state.
        }
    }
}
