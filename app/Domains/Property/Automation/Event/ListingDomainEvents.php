<?php
declare(strict_types=1);

namespace Domains\Property\Automation\Event;

use DateTimeImmutable;
use Domains\Property\Model\Listing;
use Domains\Property\Model\Publication;
use Kernel\Event\DomainEvent;
use Kernel\Event\EventMetadata;

final class ListingDomainEvents
{
    public static function created(Listing $listing, EventMetadata $metadata): DomainEvent
    {
        return self::event(PropertyEventType::LISTING_CREATED, $listing->organizationId, $listing->listingId, [
            'inventory_id' => $listing->inventoryId,
            'status' => $listing->status->value,
            'slug' => $listing->slug,
        ], $metadata);
    }

    public static function published(Listing $listing, Publication $publication, EventMetadata $metadata): DomainEvent
    {
        return self::event(PropertyEventType::LISTING_PUBLISHED, $listing->organizationId, $listing->listingId, [
            'inventory_id' => $listing->inventoryId,
            'publication_id' => $publication->publicationId,
            'channel' => $publication->channelCode,
            'external_id' => $publication->externalId,
            'external_url' => $publication->externalUrl,
        ], $metadata);
    }

    public static function hidden(Listing $listing, Publication $publication, EventMetadata $metadata): DomainEvent
    {
        return self::event(PropertyEventType::LISTING_HIDDEN, $listing->organizationId, $listing->listingId, [
            'publication_id' => $publication->publicationId,
            'channel' => $publication->channelCode,
        ], $metadata);
    }

    /** @param array<string,mixed> $payload */
    private static function event(string $type, string $organizationId, string $listingId, array $payload, EventMetadata $metadata): DomainEvent
    {
        return new DomainEvent(bin2hex(random_bytes(16)), $organizationId, $type, 'property_listing', $listingId, $payload, $metadata, new DateTimeImmutable());
    }
}
