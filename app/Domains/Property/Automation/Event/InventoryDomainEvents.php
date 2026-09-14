<?php
declare(strict_types=1);

namespace Domains\Property\Automation\Event;

use DateTimeImmutable;
use Domains\Property\Model\InventoryItem;
use Domains\Property\Model\InventoryReservation;
use Domains\Property\Model\InventoryStatus;
use Kernel\Event\DomainEvent;
use Kernel\Event\EventMetadata;

final class InventoryDomainEvents
{
    public static function created(InventoryItem $item, EventMetadata $metadata): DomainEvent
    {
        return self::event(PropertyEventType::INVENTORY_CREATED, $item->organizationId, $item->inventoryId, $item->toArray(), $metadata);
    }

    public static function priceChanged(InventoryItem $previous, InventoryItem $next, EventMetadata $metadata): DomainEvent
    {
        return self::event(PropertyEventType::INVENTORY_PRICE_CHANGED, $next->organizationId, $next->inventoryId, [
            'property_asset_id' => $next->propertyAssetId,
            'previous_price' => ['amount' => $previous->priceAmount, 'currency' => $previous->priceCurrency, 'period' => $previous->pricePeriod],
            'price' => ['amount' => $next->priceAmount, 'currency' => $next->priceCurrency, 'period' => $next->pricePeriod],
        ], $metadata);
    }

    public static function statusChanged(InventoryItem $item, InventoryStatus $previous, InventoryStatus $next, EventMetadata $metadata): DomainEvent
    {
        return self::event(PropertyEventType::INVENTORY_STATUS_CHANGED, $item->organizationId, $item->inventoryId, [
            'property_asset_id' => $item->propertyAssetId,
            'previous_status' => $previous->value,
            'status' => $next->value,
        ], $metadata);
    }

    public static function reserved(InventoryReservation $reservation, EventMetadata $metadata): DomainEvent
    {
        return self::event(PropertyEventType::INVENTORY_RESERVED, $reservation->organizationId, $reservation->inventoryId, [
            'reservation_id' => $reservation->reservationId,
            'reserved_for_reference' => $reservation->reservedForReference,
            'reserved_at' => $reservation->reservedAt->format(DATE_ATOM),
            'expires_at' => $reservation->expiresAt?->format(DATE_ATOM),
        ], $metadata);
    }

    public static function released(InventoryReservation $reservation, EventMetadata $metadata): DomainEvent
    {
        return self::event(PropertyEventType::INVENTORY_RELEASED, $reservation->organizationId, $reservation->inventoryId, [
            'reservation_id' => $reservation->reservationId,
            'released_at' => $reservation->releasedAt?->format(DATE_ATOM),
        ], $metadata);
    }

    /** @param array<string,mixed> $payload */
    private static function event(string $type, string $organizationId, string $inventoryId, array $payload, EventMetadata $metadata): DomainEvent
    {
        return new DomainEvent(
            bin2hex(random_bytes(16)),
            $organizationId,
            $type,
            'property_inventory',
            $inventoryId,
            $payload,
            $metadata,
            new DateTimeImmutable(),
        );
    }
}
