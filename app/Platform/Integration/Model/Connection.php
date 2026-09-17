<?php
declare(strict_types=1);

namespace Platform\Integration\Model;

use DateTimeImmutable;
use InvalidArgumentException;
use Kernel\Shared\Domain\OrganizationId;

final readonly class Connection
{
    /** @param array<string,mixed> $metadata */
    public function __construct(
        public string $id,
        public OrganizationId $organizationId,
        public string $connectorId,
        public ConnectionStatus $status,
        public ?string $credentialId,
        public ?string $externalAccountId,
        public array $metadata,
        public DateTimeImmutable $createdAt,
        public DateTimeImmutable $updatedAt,
    ) {
        if (trim($this->id) === '' || trim($this->connectorId) === '') {
            throw new InvalidArgumentException('Connection requires id and connector id.');
        }
    }
}
