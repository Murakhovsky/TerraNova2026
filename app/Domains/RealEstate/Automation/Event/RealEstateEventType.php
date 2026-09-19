<?php
declare(strict_types=1);

namespace Domains\RealEstate\Automation\Event;

final class RealEstateEventType
{
    public const PROPERTY_MATCHED = 'real_estate.property.matched';
    public const OFFER_CREATED = 'real_estate.offer.created';
    public const VIEWING_SCHEDULED = 'real_estate.viewing.scheduled';
    public const PROPERTY_RESERVED = 'real_estate.property.reserved';

    /** @return list<string> */
    public static function values(): array
    {
        return [
            self::PROPERTY_MATCHED,
            self::OFFER_CREATED,
            self::VIEWING_SCHEDULED,
            self::PROPERTY_RESERVED,
        ];
    }
}
