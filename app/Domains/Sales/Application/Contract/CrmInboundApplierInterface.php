<?php
declare(strict_types=1);

namespace Domains\Sales\Application\Contract;

use Domains\Sales\Application\DTO\CrmInboxItem;

interface CrmInboundApplierInterface
{
    /** @return array{event_type: string, aggregate_type: string, aggregate_id: string, payload: array<string, mixed>} */
    public function apply(CrmInboxItem $item): array;
}
