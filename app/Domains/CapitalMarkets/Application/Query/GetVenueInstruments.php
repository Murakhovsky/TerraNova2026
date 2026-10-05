<?php
declare(strict_types=1);

namespace Domains\CapitalMarkets\Application\Query;

final readonly class GetVenueInstruments
{
    public function __construct(public string $organizationId,public string $venueId){}
}
