<?php
declare(strict_types=1);

namespace Domains\Sales\Automation\Event;

use Domains\Sales\Application\Contract\PipelineRepositoryInterface;
use Domains\Sales\Application\Contract\SalesDealOwnerHistoryStoreInterface;
use Domains\Sales\Application\Service\SalesDealOwnerHistoryProjector;
use Domains\Sales\Application\Service\SalesDealStageHistoryProjector;
use Kernel\Event\Contract\DurableEventConsumerInterface;
use Kernel\Event\Contract\EventStoreInterface;
use Kernel\Event\DomainEvent;
use Kernel\Event\EventBus;
use Kernel\Event\EventMetadata;

final readonly class SalesHistoricalEventConsumer implements DurableEventConsumerInterface
{
    public function __construct(
        private SalesDealStageHistoryProjector $stageProjector,
        private SalesDealOwnerHistoryProjector $ownerProjector,
        private SalesDealOwnerHistoryStoreInterface $ownerHistory,
        private PipelineRepositoryInterface $pipelines,
        private EventBus $events,
        private EventStoreInterface $eventStore,
    ) {
    }

    public function consumerName(): string
    {
        // Keep the durable cursor name stable across V0.8.3.
        return 'sales.stage-history.v1';
    }

    public function handle(DomainEvent $event): void
    {
        if ($event->type === ClientCaseCreated::TYPE) {
            $this->canonicalizeDealCreated($event);
            return;
        }

        if (in_array($event->type, [DealCreated::TYPE, DealStageChanged::TYPE], true)) {
            $this->stageProjector->project($event);
        }
        if (in_array($event->type, [DealCreated::TYPE, SalesEventType::DEAL_OWNER_ASSIGNED], true)) {
            $this->ownerProjector->project($event);
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

        $assignedUserId = (int) ($source->payload['assigned_user_id'] ?? 0);
        if ($assignedUserId <= 0) {
            // V0.8.3 DB capture records owner changes at mutation time. Consulting that
            // interval at the source event timestamp is safe even if durable consumption
            // is delayed; reading the Deal's current owner here would be retroactive.
            $ownerAtCreation = $this->ownerHistory->ownerAt(
                $source->organizationId,
                $source->aggregateId,
                $source->occurredAt,
            );
            $assignedUserId = (int) ($ownerAtCreation['owner_user_id'] ?? 0);
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
            $assignedUserId > 0 ? $assignedUserId : null,
        );

        if ($this->eventStore->find($eventId) === null) {
            $this->events->publish($created);
        }

        $this->stageProjector->project($created);
        $this->ownerProjector->project($created);
    }
}
