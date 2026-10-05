<?php
declare(strict_types=1);

namespace Domains\CapitalMarkets\Domain\Venue;

enum VenueStatus:string
{
    case Active='ACTIVE';
    case Suspended='SUSPENDED';
    case Inactive='INACTIVE';
}
