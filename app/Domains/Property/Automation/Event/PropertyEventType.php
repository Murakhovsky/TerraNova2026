<?php
declare(strict_types=1);

namespace Domains\Property\Automation\Event;

final class PropertyEventType
{
    public const ASSET_REGISTERED = 'property.asset.registered';
    public const TYPE_CHANGED = 'property.asset.type_changed';
    public const LOCATION_CHANGED = 'property.asset.location_changed';
    public const LIFECYCLE_CHANGED = 'property.asset.lifecycle_changed';

    public const INVENTORY_CREATED = 'property.inventory.created';
    public const INVENTORY_PRICE_CHANGED = 'property.inventory.price_changed';
    public const INVENTORY_STATUS_CHANGED = 'property.inventory.status_changed';
    public const INVENTORY_RESERVED = 'property.inventory.reserved';
    public const INVENTORY_RELEASED = 'property.inventory.released';

    /** @return list<string> */
    public static function values(): array
    {
        return [
            self::ASSET_REGISTERED,
            self::TYPE_CHANGED,
            self::LOCATION_CHANGED,
            self::LIFECYCLE_CHANGED,
            self::INVENTORY_CREATED,
            self::INVENTORY_PRICE_CHANGED,
            self::INVENTORY_STATUS_CHANGED,
            self::INVENTORY_RESERVED,
            self::INVENTORY_RELEASED,
        ];
    }
}
