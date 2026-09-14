<?php
declare(strict_types=1);

namespace Domains\Property\Model;

use DateTimeImmutable;
use InvalidArgumentException;

final readonly class InventoryItem
{
    public function __construct(
        public string $organizationId,
        public string $inventoryId,
        public string $propertyAssetId,
        public InventoryTransactionType $transactionType,
        public InventoryStatus $status,
        public ?float $priceAmount,
        public string $priceCurrency = 'USD',
        public ?string $pricePeriod = 'total',
        public ?DateTimeImmutable $availableFrom = null,
        public ?DateTimeImmutable $availableUntil = null,
        public ?string $responsiblePartyReference = null,
        public ?string $sourceReference = null,
    ) {
        if (trim($this->organizationId) === '' || mb_strlen($this->organizationId) > 64) {
            throw new InvalidArgumentException('InventoryItem organization id is required.');
        }
        foreach (['inventoryId' => $this->inventoryId, 'propertyAssetId' => $this->propertyAssetId] as $field => $value) {
            if (!preg_match('/^[A-Za-z0-9][A-Za-z0-9._:-]{2,79}$/', $value)) {
                throw new InvalidArgumentException('InventoryItem ' . $field . ' must be a stable identifier.');
            }
        }
        if ($this->priceAmount !== null && $this->priceAmount < 0) {
            throw new InvalidArgumentException('InventoryItem price cannot be negative.');
        }
        if (!preg_match('/^[A-Z]{3}$/', $this->priceCurrency)) {
            throw new InvalidArgumentException('InventoryItem currency must be ISO-style three-letter code.');
        }
        if ($this->availableFrom !== null && $this->availableUntil !== null && $this->availableUntil < $this->availableFrom) {
            throw new InvalidArgumentException('InventoryItem availability interval is invalid.');
        }
    }

    public function changePrice(?float $amount, ?string $period = null, ?string $currency = null): self
    {
        return new self(
            $this->organizationId,
            $this->inventoryId,
            $this->propertyAssetId,
            $this->transactionType,
            $this->status,
            $amount,
            $currency ?? $this->priceCurrency,
            $period ?? $this->pricePeriod,
            $this->availableFrom,
            $this->availableUntil,
            $this->responsiblePartyReference,
            $this->sourceReference,
        );
    }

    public function changeStatus(InventoryStatus $status): self
    {
        return new self(
            $this->organizationId,
            $this->inventoryId,
            $this->propertyAssetId,
            $this->transactionType,
            $status,
            $this->priceAmount,
            $this->priceCurrency,
            $this->pricePeriod,
            $this->availableFrom,
            $this->availableUntil,
            $this->responsiblePartyReference,
            $this->sourceReference,
        );
    }

    /** @return array<string,mixed> */
    public function toArray(): array
    {
        return [
            'organization_id' => $this->organizationId,
            'inventory_id' => $this->inventoryId,
            'property_asset_id' => $this->propertyAssetId,
            'transaction_type' => $this->transactionType->value,
            'status' => $this->status->value,
            'price' => ['amount' => $this->priceAmount, 'currency' => $this->priceCurrency, 'period' => $this->pricePeriod],
            'available_from' => $this->availableFrom?->format(DATE_ATOM),
            'available_until' => $this->availableUntil?->format(DATE_ATOM),
            'responsible_party_reference' => $this->responsiblePartyReference,
            'source_reference' => $this->sourceReference,
        ];
    }
}
