<?php
declare(strict_types=1);

namespace Domains\Property\Automation\Event;

final class PropertyEventType
{
    public const ASSET_REGISTERED = 'property.asset.registered';
    public const TYPE_CHANGED = 'property.asset.type_changed';
    public const LOCATION_CHANGED = 'property.asset.location_changed';
    public const LIFECYCLE_CHANGED = 'property.asset.lifecycle_changed';

    /** @return list<string> */
    public static function values(): array
    {
        return [
            self::ASSET_REGISTERED,
            self::TYPE_CHANGED,
            self::LOCATION_CHANGED,
            self::LIFECYCLE_CHANGED,
        ];
    }
}
