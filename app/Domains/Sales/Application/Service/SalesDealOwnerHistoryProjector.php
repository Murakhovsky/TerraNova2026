<?php
declare(strict_types=1);

namespace Domains\Sales\Application\Service;

use Domains\Sales\Application\Contract\SalesDealOwnerHistoryStoreInterface;
use Domains\Sales\Application\DTO\SalesDealOwnerHistoryEntry;
use Domains\Sales\Automation\Event\DealCreated;
use Domains\Sales\Automation\Event\SalesEventType;
use Domains\Sales\Model\SalesHistoryQuality;
use Kernel\Event\DomainEvent;

final readonly class SalesDealOwnerHistoryProjector
{
    public function __construct(private SalesDealOwnerHistoryStoreInterface $history)
    {
    }

    public function project(DomainEvent $event): bool
    {
        if ($event->aggregateType !== 'deal') {
            return false;
        }
        if (!in_array($event->type, [DealCreated::TYPE, SalesEventType::DEAL_OWNER_ASSIGNED], true)) {
            return false;
        }
        if ($this->history->hasSourceEvent($event->organizationId, $event->id)) {
            return false;
        }

        return $event->type === DealCreated::TYPE
            ? $this->projectCreated($event)
            : $this->projectAssigned($event);
    }

    private function projectCreated(DomainEvent $event): bool
    {
        $ownerId = (int) ($event->payload['assigned_user_id'] ?? 0);
        if ($ownerId <= 0) {
            return false;
        }

        $current = $this->history->currentOwner($event->organizationId, $event->aggregateId);
        if ($current !== null) {
            $sameOwner = (int) ($current['owner_user_id'] ?? 0) === $ownerId;
            $unproven = empty($current['source_event_id'])
                && ($current['history_quality'] ?? null) !== SalesHistoryQuality::Complete->value;
            if ($sameOwner && $unproven) {
                $this->history->removeUnprovenCurrent($event->organizationId, $event->aggregateId);
            } elseif (($current['history_quality'] ?? null) === SalesHistoryQuality::Estimated->value) {
                $this->history->removeEstimatedCurrent($event->organizationId, $event->aggregateId);
            } else {
                return false;
            }
        }

        $this->history->append(new SalesDealOwnerHistoryEntry(
            $event->id,
            $event->organizationId,
            $event->aggregateId,
            $ownerId,
            $event->occurredAt,
            $event->id,
            $this->nullable($event->metadata->correlationId),
            SalesHistoryQuality::Complete,
        ));

        return true;
    }

    private function projectAssigned(DomainEvent $event): bool
    {
        $ownerId = (int) ($event->payload['owner_id'] ?? $event->payload['assigned_user_id'] ?? 0);
        if ($ownerId <= 0) {
            return false;
        }

        $current = $this->history->currentOwner($event->organizationId, $event->aggregateId);
        if ($current !== null) {
            $sameOwner = (int) ($current['owner_user_id'] ?? 0) === $ownerId;
            $unproven = empty($current['source_event_id'])
                && ($current['history_quality'] ?? null) !== SalesHistoryQuality::Complete->value;
            if ($sameOwner && $unproven) {
                $this->history->removeUnprovenCurrent($event->organizationId, $event->aggregateId);
            } else {
                $currentQuality = SalesHistoryQuality::fromStorage((string) ($current['history_quality'] ?? 'PARTIAL'));
                $chronological = $this->isChronological($current['assigned_at'] ?? null, $event);
                $this->history->closeCurrentOwner(
                    $event->organizationId,
                    (string) $current['id'],
                    $event->occurredAt,
                    $chronological ? $currentQuality : SalesHistoryQuality::Partial,
                );
            }
        }

        $this->history->append(new SalesDealOwnerHistoryEntry(
            $event->id,
            $event->organizationId,
            $event->aggregateId,
            $ownerId,
            $event->occurredAt,
            $event->id,
            $this->nullable($event->metadata->correlationId),
            SalesHistoryQuality::Complete,
        ));

        return true;
    }

    private function isChronological(mixed $assignedAt, DomainEvent $event): bool
    {
        if ($assignedAt === null || $assignedAt === '') {
            return true;
        }
        if ($assignedAt instanceof \DateTimeInterface) {
            return $assignedAt <= $event->occurredAt;
        }
        try {
            return new \DateTimeImmutable((string) $assignedAt) <= $event->occurredAt;
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
