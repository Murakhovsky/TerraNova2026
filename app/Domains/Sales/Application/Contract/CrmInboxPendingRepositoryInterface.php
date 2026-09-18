<?php
declare(strict_types=1);

namespace Domains\Sales\Application\Contract;

interface CrmInboxPendingRepositoryInterface
{
    /** @return array<int,array{organization_id:string,id:string,correlation_id:string}> */
    public function pending(int $limit = 100): array;
}
