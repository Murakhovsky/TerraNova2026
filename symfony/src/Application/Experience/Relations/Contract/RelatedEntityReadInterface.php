<?php
declare(strict_types=1);

namespace App\Application\Experience\Relations\Contract;

use App\Application\Experience\Relations\RelatedEntityReference;

interface RelatedEntityReadInterface
{
    /** @return list<RelatedEntityReference> */
    public function related(string $organizationId, string $entityType, string $entityId, int $limit = 10): array;
}
