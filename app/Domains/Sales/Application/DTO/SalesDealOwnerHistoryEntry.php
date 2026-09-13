<?php
declare(strict_types=1);

namespace Domains\Sales\Application\DTO;

use DateTimeImmutable;
use Domains\Sales\Model\SalesHistoryQuality;

final readonly class SalesDealOwnerHistoryEntry
{
    public function __construct(
        public string $id,
        public string $organizationId,
        public string $dealId,
        public int $ownerUserId,
        public ?DateTimeImmutable $assignedAt,
        public ?string $sourceEventId,
        public ?string $correlationId,
        public SalesHistoryQuality $historyQuality,
    ) {
    }
}
