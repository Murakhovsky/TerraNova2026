<?php
declare(strict_types=1);

namespace Domains\Sales\Application\Service;

use Domains\Sales\Application\Contract\SalesDealStageHistoryStoreInterface;
use Domains\Sales\Application\DTO\SalesDealStageHistoryEntry;
use Domains\Sales\Automation\Event\DealCreated;
use Domains\Sales\Automation\Event\DealStageChanged;
use Domains\Sales\Model\SalesHistoryQuality;
use Kernel\Event\DomainEvent;

final readonly class SalesDealStageHistoryProjector
{
    public function __construct(private SalesDealStageHistoryStoreInterface $history)
    {
    }

    public function project(DomainEvent $event): bool
    {
        if ($event->aggregateType !== 'deal') {
            return false;
        }
        if (!in_array($event->type, [DealCreated::TYPE, DealStageChanged::TYPE], true)) {
            return false;
        }
        if ($this->history->hasSourceEvent($event->organizationId, $event->id)) {
            return false;
        }

        return $event->type === DealCreated::TYPE
            ? $this->projectCreated($event)
            : $this->projectStageChanged($event);
    }

    private function projectCreated(DomainEvent $event): bool
    {
        $pipelineId = $this->nullable($event->payload['pipeline_id'] ?? null);
        $stageId = trim((string) ($event->payload['stage_id'] ?? ''));
        $stageCode = trim((string) ($event->payload['stage_code'] ?? $event->payload['stage'] ?? ''));
        if ($stageId === '' || $stageCode === '') {
            return false;
        }

        $current = $this->history->currentStage($event->organizationId, $event->aggregateId);
        if ($current !== null) {
            if (($current['history_quality'] ?? null) === SalesHistoryQuality::Estimated->value) {
                $this->history->removeEstimatedCurrent($event->organizationId, $event->aggregateId);
            } else {
                // An already established exact/partial open row wins over a duplicate creation signal.
                return false;
            }
        }

        $this->history->append(new SalesDealStageHistoryEntry(
            $event->id,
            $event->organizationId,
            $event->aggregateId,
            $pipelineId,
            null,
            null,
            $stageId,
            $stageCode,
            $event->occurredAt,
            $event->id,
            $this->nullable($event->metadata->correlationId),
            SalesHistoryQuality::Complete,
        ));

        return true;
    }

    private function projectStageChanged(DomainEvent $event): bool
    {
        $pipelineId = $this->nullable($event->payload['pipeline_id'] ?? null);
        $fromStageId = trim((string) ($event->payload['previous_stage_id'] ?? ''));
        $fromStageCode = trim((string) ($event->payload['previous_stage_code'] ?? ''));
        $toStageId = trim((string) ($event->payload['stage_id'] ?? ''));
        $toStageCode = trim((string) ($event->payload['stage_code'] ?? ''));
        if ($fromStageId === '' || $fromStageCode === '' || $toStageId === '' || $toStageCode === '') {
            return false;
        }

        $current = $this->history->currentStage($event->organizationId, $event->aggregateId);
        $chronological = $current === null || $this->isChronological($current['entered_at'] ?? null, $event);
        $chainMatches = $current !== null
            && $chronological
            && (string) ($current['to_stage_id'] ?? '') === $fromStageId
            && (string) ($current['to_stage_code'] ?? '') === $fromStageCode;

        $newQuality = SalesHistoryQuality::Partial;
        if ($current !== null) {
            $currentQuality = SalesHistoryQuality::fromStorage((string) ($current['history_quality'] ?? 'PARTIAL'));
            $closedQuality = $chainMatches ? $currentQuality : SalesHistoryQuality::Partial;
            $this->history->closeCurrentStage(
                $event->organizationId,
                (string) $current['id'],
                $event->occurredAt,
                $closedQuality,
            );

            if ($chainMatches && $currentQuality === SalesHistoryQuality::Complete) {
                $newQuality = SalesHistoryQuality::Complete;
            }
        }

        $this->history->append(new SalesDealStageHistoryEntry(
            $event->id,
            $event->organizationId,
            $event->aggregateId,
            $pipelineId,
            $fromStageId,
            $fromStageCode,
            $toStageId,
            $toStageCode,
            $event->occurredAt,
            $event->id,
            $this->nullable($event->metadata->correlationId),
            $newQuality,
        ));

        return true;
    }

    private function isChronological(mixed $enteredAt, DomainEvent $event): bool
    {
        if ($enteredAt === null || $enteredAt === '') {
            return true;
        }
        if ($enteredAt instanceof \DateTimeInterface) {
            return $enteredAt <= $event->occurredAt;
        }

        try {
            return new \DateTimeImmutable((string) $enteredAt) <= $event->occurredAt;
        } catch (\Throwable) {
            return false;
        }
    }

    private function nullable(mixed $value): ?string
    {
        $value = trim((string) $value);
        return $value !== '' ? $value : null;
    }
}
