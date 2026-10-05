<?php
declare(strict_types=1);

namespace Domains\CapitalMarkets\Application\Query;

final readonly class ListVenues
{
    public function __construct(public string $organizationId,public int $limit=100){}
}
