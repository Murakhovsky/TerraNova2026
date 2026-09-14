<?php
declare(strict_types=1);

namespace Domains\Property\Application\Contract;

use Domains\Property\Model\InventoryItem;
use Domains\Property\Model\InventoryReservation;
use Domains\Property\Model\Listing;
use Domains\Property\Model\PropertyAsset;
use Domains\Property\Model\Publication;

interface PropertyCanonicalRuntimeRepositoryInterface
{
    public function findAsset(string $organizationId, string $assetId): ?PropertyAsset;
    public function assetIdForLegacy(string $organizationId, int $legacyPropertyId): ?string;
    public function saveAsset(PropertyAsset $asset): void;

    public function findInventory(string $organizationId, string $inventoryId): ?InventoryItem;
    public function primaryInventoryForAsset(string $organizationId, string $assetId): ?InventoryItem;
    public function saveInventory(
        InventoryItem $item,
        ?string $priceEventId = null,
        ?string $statusEventId = null,
        ?string $statusReason = null,
    ): void;

    public function findActiveReservation(string $organizationId, string $inventoryId): ?InventoryReservation;
    public function saveReservation(InventoryReservation $reservation): void;
    public function releaseReservation(string $organizationId, string $reservationId, string $releasedAt): ?InventoryReservation;

    public function findListing(string $organizationId, string $listingId): ?Listing;
    public function primaryListingForInventory(string $organizationId, string $inventoryId): ?Listing;
    public function saveListing(Listing $listing): void;

    public function findPublication(string $organizationId, string $publicationId): ?Publication;
    public function findPublicationForChannel(string $organizationId, string $listingId, string $channelCode): ?Publication;
    public function savePublication(Publication $publication, ?string $eventId = null): void;
}
