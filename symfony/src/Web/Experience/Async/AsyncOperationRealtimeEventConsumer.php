<?php

declare(strict_types=1);

namespace App\Web\Experience\Async;

use App\Web\Experience\Realtime\RealtimeStreamPublisher;
use App\Web\Experience\Realtime\RealtimeTopicFactory;
use Kernel\Event\Contract\DurableEventConsumerInterface;
use Kernel\Event\DomainEvent;
use Kernel\Queue\Contract\AsyncOperationReadModelInterface;
use Kernel\Queue\Event\AsyncOperationChanged;

final readonly class AsyncOperationRealtimeEventConsumer implements DurableEventConsumerInterface
{
    public function __construct(
        private AsyncOperationReadModelInterface $operations,
        private RealtimeTopicFactory $topics,
        private RealtimeStreamPublisher $publisher,
    ) {
    }

    public function consumerName(): string
    {
        return 'platform.async-operations-realtime.v1';
    }

    public function handle(DomainEvent $event): void
    {
        if ($event->type !== AsyncOperationChanged::TYPE) {
            return;
        }

        $operation = $this->operations->find($event->organizationId, $event->aggregateId);
        if ($operation === null) {
            return;
        }

        $this->publisher->publish(
            $this->topics->organization($event->organizationId),
            'experience/realtime/streams/async_operation_signal.stream.html.twig',
            ['operation' => $operation],
        );
    }
}
