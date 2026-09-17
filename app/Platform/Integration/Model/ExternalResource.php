<?php
declare(strict_types=1);

namespace Platform\Integration\Model;

use DateTimeImmutable;
use InvalidArgumentException;
use Kernel\Shared\Domain\OrganizationId;

final readonly class ExternalResource
{
    /** @param array<string,mixed> $snapshot */
    public function __construct(
        public string $id,
        public OrganizationId $organizationId,
        public string $connectionId,
        public string $resourceType,
        public string $externalId,
        public ?string $localReference,
        public array $snapshot,
        public DateTimeImmutable $syncedAt,
    ) {
        if (trim($this->id) === '' || trim($this->connectionId) === '' || trim($this->resourceType) === '' || trim($this->externalId) === '') {
            throw new InvalidArgumentException('External resource requires stable local and external identity.');
        }
    }
}
