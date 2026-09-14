<?php
declare(strict_types=1);

namespace Domains\Property\Model;

use InvalidArgumentException;

final readonly class Listing
{
    public function __construct(
        public string $organizationId,
        public string $listingId,
        public string $inventoryId,
        public ListingStatus $status,
        public string $title,
        public string $description,
        public ?float $presentationPriceAmount,
        public string $presentationPriceCurrency,
        public string $slug,
        public string $visibility = 'public',
        public ?string $seoTitle = null,
        public ?string $seoDescription = null,
        public array $publicFeatures = [],
    ) {
        if (trim($this->organizationId) === '' || trim($this->listingId) === '' || trim($this->inventoryId) === '') throw new InvalidArgumentException('Listing identity is required.');
        if (trim($this->title) === '' || trim($this->slug) === '') throw new InvalidArgumentException('Listing title and slug are required.');
        if ($this->presentationPriceAmount !== null && $this->presentationPriceAmount < 0) throw new InvalidArgumentException('Listing presentation price cannot be negative.');
        if (!preg_match('/^[A-Z]{3}$/', $this->presentationPriceCurrency)) throw new InvalidArgumentException('Listing currency must be a three-letter code.');
    }

    public function changeStatus(ListingStatus $status): self
    {
        return new self($this->organizationId, $this->listingId, $this->inventoryId, $status, $this->title, $this->description,
            $this->presentationPriceAmount, $this->presentationPriceCurrency, $this->slug, $this->visibility,
            $this->seoTitle, $this->seoDescription, $this->publicFeatures);
    }
}
