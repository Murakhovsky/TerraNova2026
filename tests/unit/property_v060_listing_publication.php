<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);
require $root . '/vendor/autoload.php';

use Domains\Property\Model\Listing;
use Domains\Property\Model\ListingMedia;
use Domains\Property\Model\ListingStatus;
use Domains\Property\Model\Publication;
use Domains\Property\Model\PublicationState;

$assert = static function (bool $condition, string $message): void { if (!$condition) throw new RuntimeException($message); };

$listing = new Listing('org-a','LST-52','INV-52-SALE',ListingStatus::from('ready'),'Apartment #52','Public presentation',107000.0,'USD','apartment-52','public','Apartment 52','Two-room apartment',['balcony'=>true]);
$olx = new Publication('org-a','PUB-52-OLX','LST-52','olx',PublicationState::from('published'),'OLX-5521','https://example.test/olx/5521');
$dim = new Publication('org-a','PUB-52-DIM','LST-52','dimria',PublicationState::from('failed'));
$media = new ListingMedia('org-a','LST-52','PROPERTY_MEDIA:IMG-1',10,true);

$assert($listing->inventoryId === 'INV-52-SALE', 'Listing must describe a commercial InventoryItem, not own the physical Property.');
$assert($olx->listingId === $dim->listingId && $olx->channelCode !== $dim->channelCode, 'One Listing must publish independently to multiple channels.');
$assert($media->mediaReference === 'PROPERTY_MEDIA:IMG-1', 'ListingMedia must select canonical PropertyMedia instead of duplicating the asset media model.');
$published = $listing->changeStatus(ListingStatus::from('published'));
$assert($published->status->value === 'published' && $listing->status->value === 'ready', 'Listing lifecycle must be independent and immutable.');

echo "Property V0.6 listing and publication model: OK\n";
