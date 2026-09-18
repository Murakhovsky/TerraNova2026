<?php
declare(strict_types=1);

namespace Platform\Search\Model;

use InvalidArgumentException;

final readonly class SearchResult
{
    /** @param list<SearchHit> $hits */
    public function __construct(
        public array $hits,
        public int $total,
    ) {
        if ($this->total < 0) {
            throw new InvalidArgumentException('Search result total cannot be negative.');
        }
        foreach ($this->hits as $hit) {
            if (!$hit instanceof SearchHit) {
                throw new InvalidArgumentException('Search result contains an invalid hit.');
            }
        }
    }
}
