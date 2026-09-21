<?php

declare(strict_types=1);

namespace App\Web\Experience\Extension\Contract;

use App\Web\Experience\Extension\Model\SearchResult;
use App\Web\Experience\Extension\Model\WebExtensionContext;

interface SearchProviderInterface extends WebExtensionProviderInterface
{
    /** @return list<SearchResult> */
    public function search(WebExtensionContext $context, string $query, int $limit = 10): array;
}
