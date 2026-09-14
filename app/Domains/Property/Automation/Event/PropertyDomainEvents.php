<?php
declare(strict_types=1);

namespace Domains\Property\Automation\Event;

use DateTimeImmutable;
use Domains\Property\Model\PropertyAsset;
use Domains\Property\Model\PropertyLifecycle;
use Domains\Property\Model\PropertyLocation;
use Domains\Property\Model\PropertyType;
use Kernel\Event\DomainEvent;
use Kernel\Event\EventMetadata;

final class PropertyDomainEvents
{
    public static function assetRegistered(PropertyAsset $asset, EventMetadata $metadata): DomainEvent
    {
        $snapshot = $asset->toArray();

        return self::event(
            PropertyEventType::ASSET_REGISTERED,
            $asset->organizationId,
            $asset->assetId,
            [
                'type' => $snapshot['type'],
                'location' => $snapshot['location'],
                'lifecycle' => $snapshot['lifecycle'],
                'physical' => $snapshot['physical'],
            ],
            $metadata,
        );
    }

    public static function typeChanged(
        string $organizationId,
        string $assetId,
        PropertyType $previous,
        PropertyType $next,
        EventMetadata $metadata,
    ): DomainEvent {
        return self::event(
            PropertyEventType::TYPE_CHANGED,
            $organizationId,
            $assetId,
            [
                'previous_type' => ['code' => $previous->code, 'reference_id' => $previous->referenceId],
                'type' => ['code' => $next->code, 'reference_id' => $next->referenceId],
            ],
            $metadata,
        );
    }

    public static function locationChanged(
        string $organizationId,
        string $assetId,
        PropertyLocation $previous,
        PropertyLocation $next,
        EventMetadata $metadata,
    ): DomainEvent {
        return self::event(
            PropertyEventType::LOCATION_CHANGED,
            $organizationId,
            $assetId,
            [
                'previous_location' => $previous->toArray(),
                'location' => $next->toArray(),
            ],
            $metadata,
        );
    }

    public static function lifecycleChanged(
        string $organizationId,
        string $assetId,
        PropertyLifecycle $previous,
        PropertyLifecycle $next,
        EventMetadata $metadata,
    ): DomainEvent {
        return self::event(
            PropertyEventType::LIFECYCLE_CHANGED,
            $organizationId,
            $assetId,
            [
                'previous_lifecycle' => $previous->value,
                'lifecycle' => $next->value,
            ],
            $metadata,
        );
    }

    private static function event(
        string $type,
        string $organizationId,
        string $assetId,
        array $payload,
        EventMetadata $metadata,
    ): DomainEvent {
        return new DomainEvent(
            bin2hex(random_bytes(16)),
            $organizationId,
            $type,
            'property_asset',
            $assetId,
            $payload,
            $metadata,
            new DateTimeImmutable(),
        );
    }
}
