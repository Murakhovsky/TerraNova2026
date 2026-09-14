<?php
declare(strict_types=1);

namespace Domains\Property\Automation\Event;

final class PropertyEventType
{
    // Compatibility event names kept for V0.2 consumers.
    public const ASSET_REGISTERED = 'property.asset.registered';
    public const TYPE_CHANGED = 'property.asset.type_changed';
    public const LOCATION_CHANGED = 'property.asset.location_changed';
    public const LIFECYCLE_CHANGED = 'property.asset.lifecycle_changed';

    // Canonical V0.7 physical asset event vocabulary.
    public const PROPERTY_CREATED = 'property.created';
    public const PROPERTY_STRUCTURE_CHANGED = 'property.structure_changed';
    public const PROPERTY_LIFECYCLE_CHANGED = 'property.lifecycle_changed';
    public const PROPERTY_RELATION_CHANGED = 'property.relation_changed';

    public const INVENTORY_CREATED = 'property.inventory.created';
    public const INVENTORY_PRICE_CHANGED = 'property.inventory.price_changed';
    public const INVENTORY_STATUS_CHANGED = 'property.inventory.status_changed';
    public const INVENTORY_RESERVED = 'property.inventory.reserved';
    public const INVENTORY_RELEASED = 'property.inventory.released';
    public const INVENTORY_AVAILABLE = 'property.inventory.available';

    public const LISTING_CREATED = 'property.listing.created';
    public const LISTING_PUBLISHED = 'property.listing.published';
    public const LISTING_HIDDEN = 'property.listing.hidden';

    /** @return list<string> */
    public static function values(): array
    {
        return [
            self::ASSET_REGISTERED, self::TYPE_CHANGED, self::LOCATION_CHANGED, self::LIFECYCLE_CHANGED,
            self::PROPERTY_CREATED, self::PROPERTY_STRUCTURE_CHANGED, self::PROPERTY_LIFECYCLE_CHANGED, self::PROPERTY_RELATION_CHANGED,
            self::INVENTORY_CREATED, self::INVENTORY_PRICE_CHANGED, self::INVENTORY_STATUS_CHANGED,
            self::INVENTORY_RESERVED, self::INVENTORY_RELEASED, self::INVENTORY_AVAILABLE,
            self::LISTING_CREATED, self::LISTING_PUBLISHED, self::LISTING_HIDDEN,
        ];
    }
}
