<?php

declare(strict_types=1);

namespace App\Web\Experience\Search;

use App\Web\Experience\Extension\Model\SearchResult;

final readonly class GlobalSearchResultSet
{
    /**
     * @param list<SearchResult> $items
     */
    public function __construct(
        public string $query,
        public array $items,
        public int $limit,
    ) {
    }

    public function isEmpty(): bool
    {
        return $this->items === [];
    }
}
