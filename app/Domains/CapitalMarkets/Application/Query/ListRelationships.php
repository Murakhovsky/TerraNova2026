<?php
declare(strict_types=1);

namespace Domains\CapitalMarkets\Application\Query;

final readonly class ListRelationships
{
    public function __construct(public string $organizationId,public int $limit=200){}
}
