<?php
declare(strict_types=1);

namespace Platform\Search\Contract;

use Platform\Search\Model\SearchQuery;
use Platform\Search\Model\SearchResult;

interface SearchEngineInterface
{
    public function search(SearchQuery $query): SearchResult;
}
