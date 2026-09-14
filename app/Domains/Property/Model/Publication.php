<?php
declare(strict_types=1);

namespace Domains\Property\Model;

use DateTimeImmutable;
use InvalidArgumentException;

final readonly class Publication
{
    public function __construct(
        public string $organizationId,
        public string $publicationId,
        public string $listingId,
        public string $channelCode,
        public PublicationState $state,
        public ?string $externalId = null,
        public ?string $externalUrl = null,
        public ?DateTimeImmutable $publishedAt = null,
        public ?DateTimeImmutable $hiddenAt = null,
        public ?DateTimeImmutable $expiresAt = null,
        public ?DateTimeImmutable $lastSyncedAt = null,
        public string $syncStatus = 'pending',
        public ?string $syncError = null,
    ) {
        if (trim($this->organizationId) === '' || trim($this->publicationId) === '' || trim($this->listingId) === '' || trim($this->channelCode) === '') {
            throw new InvalidArgumentException('Publication identity, listing and channel are required.');
        }
    }
}
