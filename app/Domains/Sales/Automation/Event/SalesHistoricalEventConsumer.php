<?php
declare(strict_types=1);

namespace Domains\Sales\Automation\Event;

use Domains\Sales\Application\Contract\PipelineRepositoryInterface;
use Domains\Sales\Application\Service\SalesDealStageHistoryProjector;
use Kernel\Event\Contract\DurableEventConsumerInterface;
use Kernel\Event\Contract\EventStoreInterface;
use Kernel\Event\DomainEvent;
use Kernel\Event\EventBus;
use Kernel\Event\EventMetadata;

final readonly class SalesHistoricalEventConsumer implements DurableEventConsumerInterface
{
    public function __construct(
        private SalesDealStageHistoryProjector $projector,
        private PipelineRepositoryInterface $pipelines,
        private EventBus $events,
        private EventStoreInterface $eventStore,
    ) {
    }

    public function consumerName(): string
    {
        return 'sales.stage-history.v1';
    }

    public function handle(DomainEvent $event): void
    {
        if ($event->type === ClientCaseCreated::TYPE) {
            $this->canonicalizeDealCreated($event);
            return;
        }

        if (in_array($event->type, [DealCreated::TYPE, DealStageChanged::TYPE], true)) {
            $this->projector->project($event);
        }
    }

    private function canonicalizeDealCreated(DomainEvent $source): void
    {
        $pipelineId = trim((string) ($source->payload['pipeline_id'] ?? ''));
        $stageId = trim((string) ($source->payload['stage_id'] ?? ''));
        if ($pipelineId === '' || $stageId === '' || trim($source->aggregateId) === '') {
            return;
        }

        $pipeline = $this->pipelines->getPipeline($source->organizationId, $pipelineId);
        $stage = $this->pipelines->getStage($source->organizationId, $stageId);
        if ($pipeline === null || $stage === null) {
            return;
        }

        // Prove that the stage snapshot belongs to the declared pipeline.
        $pipelineStage = $this->pipelines->findStageByCode($source->organizationId, $pipelineId, $stage->code);
        if ($pipelineStage === null || $pipelineStage->id !== $stageId) {
            return;
        }

        $snapshotStageCode = strtoupper(trim((string) (
            $source->payload['stage_code'] ?? $source->payload['stage'] ?? $stage->code
        )));
        if ($snapshotStageCode === '') {
            $snapshotStageCode = $stage->code;
        }

        $eventId = substr(hash('sha256', DealCreated::TYPE . ':' . $source->id), 0, 32);
        $created = DealCreated::create(
            $eventId,
            $source->organizationId,
            $source->aggregateId,
            $pipelineId,
            $stageId,
            $snapshotStageCode,
            new EventMetadata(
                $source->metadata->correlationId !== '' ? $source->metadata->correlationId : $source->id,
                $source->id,
                $source->metadata->actorType,
                $source->metadata->actorId,
                $source->metadata->schemaVersion,
            ),
            $source->occurredAt,
        );

        if ($this->eventStore->find($eventId) === null) {
            $this->events->publish($created);
        }

        // Project now, before a later stage-change outbox row can be consumed.
        // Delivery of the derived event itself is idempotent and will become a no-op here.
        $this->projector->project($created);
    }
}
