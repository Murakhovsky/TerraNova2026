<?php
declare(strict_types=1);

namespace Domains\Sales\Application\Contract;

use DateTimeImmutable;
use Domains\Sales\Application\DTO\SalesDealOwnerHistoryEntry;
use Domains\Sales\Model\SalesHistoryQuality;

interface SalesDealOwnerHistoryStoreInterface
{
    public function hasSourceEvent(string $organizationId, string $eventId): bool;

    /** @return array<string,mixed>|null */
    public function currentOwner(string $organizationId, string $dealId): ?array;

    /** @return array<string,mixed>|null */
    public function ownerAt(string $organizationId, string $dealId, DateTimeImmutable $at): ?array;

    public function append(SalesDealOwnerHistoryEntry $entry): void;

    public function closeCurrentOwner(
        string $organizationId,
        string $historyId,
        DateTimeImmutable $unassignedAt,
        SalesHistoryQuality $quality,
    ): void;

    public function removeEstimatedCurrent(string $organizationId, string $dealId): void;

    public function removeUnprovenCurrent(string $organizationId, string $dealId): void;

    public function clearOrganization(string $organizationId): void;

    public function backfillCurrentState(string $organizationId): int;
}
