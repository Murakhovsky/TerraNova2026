<?php
declare(strict_types=1);

namespace Domains\Sales\Application\Contract;

use DateTimeImmutable;
use Domains\Sales\Application\DTO\SalesDealStageHistoryEntry;
use Domains\Sales\Model\SalesHistoryQuality;

interface SalesDealStageHistoryStoreInterface
{
    public function hasSourceEvent(string $organizationId, string $eventId): bool;

    /** @return array<string, mixed>|null */
    public function currentStage(string $organizationId, string $dealId): ?array;

    public function append(SalesDealStageHistoryEntry $entry): void;

    public function closeCurrentStage(
        string $organizationId,
        string $historyId,
        DateTimeImmutable $leftAt,
        SalesHistoryQuality $quality,
    ): void;

    public function removeEstimatedCurrent(string $organizationId, string $dealId): void;

    public function clearOrganization(string $organizationId): void;

    public function backfillCurrentState(string $organizationId): int;
}
