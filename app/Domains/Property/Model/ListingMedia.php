<?php
declare(strict_types=1);

namespace Domains\Property\Model;

use InvalidArgumentException;

final readonly class ListingMedia
{
    public function __construct(
        public string $organizationId,
        public string $listingId,
        public string $mediaReference,
        public int $sortOrder = 100,
        public bool $isCover = false,
        public ?string $caption = null,
    ) {
        if (trim($this->organizationId) === '' || trim($this->listingId) === '' || trim($this->mediaReference) === '') {
            throw new InvalidArgumentException('ListingMedia requires tenant, listing and canonical media reference.');
        }
        if ($this->sortOrder < 0) throw new InvalidArgumentException('ListingMedia sort order cannot be negative.');
    }
}
