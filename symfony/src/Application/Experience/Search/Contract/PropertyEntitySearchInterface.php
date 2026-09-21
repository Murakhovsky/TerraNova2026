<?php

declare(strict_types=1);

namespace App\Application\Experience\Search\Contract;

use App\Application\Experience\Search\EntitySearchHit;

interface PropertyEntitySearchInterface
{
    /** @return list<EntitySearchHit> */
    public function search(string $organizationId, string $query, int $limit = 10): array;
}
