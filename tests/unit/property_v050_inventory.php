<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);
require $root . '/vendor/autoload.php';

use Domains\Property\Model\InventoryItem;
use Domains\Property\Model\InventoryReservation;
use Domains\Property\Model\InventoryStatus;
use Domains\Property\Model\InventoryTransactionType;

$assert = static function (bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
};

$sale = new InventoryItem(
    'org-a', 'INV-52-SALE', 'TN-052',
    InventoryTransactionType::from(InventoryTransactionType::SALE),
    InventoryStatus::available(),
    105000.00, 'USD', 'total',
);
$rent = new InventoryItem(
    'org-a', 'INV-52-RENT', 'TN-052',
    InventoryTransactionType::from(InventoryTransactionType::RENT),
    InventoryStatus::available(),
    900.00, 'USD', 'month',
);
$partnerSale = new InventoryItem(
    'org-b', 'INV-52-PARTNER', 'TN-052',
    InventoryTransactionType::from(InventoryTransactionType::SALE),
    InventoryStatus::available(),
    108000.00, 'USD', 'total',
);

$assert($sale->propertyAssetId === $rent->propertyAssetId, 'One PropertyAsset must support multiple commercial offers in one organization.');
$assert($sale->inventoryId !== $rent->inventoryId, 'InventoryItem must have identity independent from PropertyAsset.');
$assert($partnerSale->priceAmount === 108000.00 && $sale->priceAmount === 105000.00, 'Different organizations may commercialize their tenant-local representation independently.');

$repriced = $sale->changePrice(102500.00);
$assert($sale->priceAmount === 105000.00 && $repriced->priceAmount === 102500.00, 'Price changes must produce a new commercial snapshot without changing Property.');

$reserved = $repriced->changeStatus(InventoryStatus::from(InventoryStatus::RESERVED));
$assert($reserved->status->value === InventoryStatus::RESERVED, 'Reservation is Inventory state, never Property lifecycle.');

$reservation = new InventoryReservation(
    'org-a', 'RSV-52-1', 'INV-52-SALE', 'CRM:person:P-883',
    new DateTimeImmutable('2026-09-14T15:00:00+03:00'),
    new DateTimeImmutable('2026-09-16T15:00:00+03:00'),
);
$assert($reservation->isActiveAt(new DateTimeImmutable('2026-09-15T10:00:00+03:00')), 'Reservation must be temporal.');
$released = $reservation->release(new DateTimeImmutable('2026-09-15T12:00:00+03:00'));
$assert(!$released->isActiveAt(new DateTimeImmutable('2026-09-15T13:00:00+03:00')), 'Released reservation must stop blocking Inventory.');

$assert(in_array('investment', InventoryTransactionType::values(), true), 'Legacy investment deal type must survive the V0.5 compatibility migration.');
$assert(InventoryStatus::from(InventoryStatus::AVAILABLE)->isMarketable(), 'AVAILABLE inventory must be marketable.');
$assert(InventoryStatus::from(InventoryStatus::SOLD)->isClosed(), 'SOLD is a commercial terminal state, not a physical lifecycle state.');

echo "Property V0.5 inventory model: OK\n";
