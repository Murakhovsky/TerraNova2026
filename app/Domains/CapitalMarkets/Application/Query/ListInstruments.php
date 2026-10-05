<?php
declare(strict_types=1);

namespace Domains\CapitalMarkets\Application\Query;

final readonly class ListInstruments
{
    /** @param array<string,string> $filters */
    public function __construct(public string $organizationId,public array $filters=[],public int $limit=100){}
}
