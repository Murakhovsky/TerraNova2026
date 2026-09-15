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
        return self::event(PropertyEventType::ASSET_REGISTERED, $asset->organizationId, $asset->assetId, [
            'type' => $snapshot['type'], 'location' => $snapshot['location'], 'lifecycle' => $snapshot['lifecycle'],
            'physical' => $snapshot['physical'], 'kind' => $snapshot['kind'],
        ], $metadata);
    }

    /** @return list<DomainEvent> */
    public static function changes(PropertyAsset $previous, PropertyAsset $next, EventMetadata $metadata): array
    {
        if ($previous->organizationId !== $next->organizationId || $previous->assetId !== $next->assetId) {
            throw new \InvalidArgumentException('Property change events require the same canonical asset identity.');
        }
        $events = [];
        if (!$previous->type->equals($next->type)) $events[] = self::typeChanged($next->organizationId, $next->assetId, $previous->type, $next->type, $metadata);
        if ($previous->location->toArray() !== $next->location->toArray()) $events[] = self::locationChanged($next->organizationId, $next->assetId, $previous->location, $next->location, $metadata);
        if ($previous->lifecycle->value !== $next->lifecycle->value) $events[] = self::lifecycleChanged($next->organizationId, $next->assetId, $previous->lifecycle, $next->lifecycle, $metadata);
        $before = $previous->toArray(); $after = $next->toArray();
        if (($before['physical'] ?? []) !== ($after['physical'] ?? []) || ($before['kind'] ?? null) !== ($after['kind'] ?? null)) {
            $events[] = self::event(PropertyEventType::PROPERTY_STRUCTURE_CHANGED, $next->organizationId, $next->assetId, [
                'previous_kind' => $before['kind'] ?? null, 'kind' => $after['kind'] ?? null,
                'previous_physical' => $before['physical'] ?? [], 'physical' => $after['physical'] ?? [],
            ], $metadata);
        }
        return $events;
    }

    public static function typeChanged(string $organizationId, string $assetId, PropertyType $previous, PropertyType $next, EventMetadata $metadata): DomainEvent
    {
        return self::event(PropertyEventType::TYPE_CHANGED, $organizationId, $assetId, [
            'previous_type' => ['code' => $previous->code, 'reference_id' => $previous->referenceId],
            'type' => ['code' => $next->code, 'reference_id' => $next->referenceId],
        ], $metadata);
    }

    public static function locationChanged(string $organizationId, string $assetId, PropertyLocation $previous, PropertyLocation $next, EventMetadata $metadata): DomainEvent
    {
        return self::event(PropertyEventType::LOCATION_CHANGED, $organizationId, $assetId, [
            'previous_location' => $previous->toArray(), 'location' => $next->toArray(),
        ], $metadata);
    }

    public static function lifecycleChanged(string $organizationId, string $assetId, PropertyLifecycle $previous, PropertyLifecycle $next, EventMetadata $metadata): DomainEvent
    {
        return self::event(PropertyEventType::LIFECYCLE_CHANGED, $organizationId, $assetId, [
            'previous_lifecycle' => $previous->value, 'lifecycle' => $next->value,
        ], $metadata);
    }

    private static function event(string $type, string $organizationId, string $assetId, array $payload, EventMetadata $metadata): DomainEvent
    {
        return new DomainEvent(bin2hex(random_bytes(16)), $organizationId, $type, 'property_asset', $assetId, $payload, $metadata, new DateTimeImmutable());
    }
}
