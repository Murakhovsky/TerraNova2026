<?php
declare(strict_types=1);

namespace Platform\Knowledge\Model;

use DateTimeImmutable;
use InvalidArgumentException;
use Kernel\Shared\Domain\OrganizationId;

final readonly class Document
{
    /** @param array<string,mixed> $metadata */
    public function __construct(
        public string $id,
        public OrganizationId $organizationId,
        public string $sourceId,
        public string $title,
        public string $mimeType,
        public ?string $checksum,
        public array $metadata,
        public DateTimeImmutable $createdAt,
        public DateTimeImmutable $updatedAt,
    ) {
        if (trim($this->id) === '' || trim($this->sourceId) === '' || trim($this->title) === '') {
            throw new InvalidArgumentException('Knowledge document requires id, source and title.');
        }
    }
}
