<?php
declare(strict_types=1);

namespace Domains\CapitalMarkets\Application\Contract;

use Domains\CapitalMarkets\Domain\Venue\VenueDescriptor;
use Domains\CapitalMarkets\Domain\Venue\VenueId;
use Domains\CapitalMarkets\Domain\Venue\VenueType;

interface VenueCatalogInterface
{
    public function get(VenueId $id): ?VenueDescriptor;

    /** @return list<VenueDescriptor> */
    public function all(): array;

    /** @return list<VenueDescriptor> */
    public function byType(VenueType $type): array;
}
