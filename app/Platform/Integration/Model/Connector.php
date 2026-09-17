<?php
declare(strict_types=1);

namespace Platform\Integration\Model;

use DateTimeImmutable;
use InvalidArgumentException;
use Kernel\Shared\Domain\OrganizationId;

final readonly class Connector
{
    /** @param array<string,mixed> $settings */
    public function __construct(
        public string $id,
        public OrganizationId $organizationId,
        public string $definitionKey,
        public bool $enabled,
        public array $settings,
        public DateTimeImmutable $createdAt,
        public DateTimeImmutable $updatedAt,
    ) {
        if (trim($this->id) === '' || trim($this->definitionKey) === '') {
            throw new InvalidArgumentException('Connector requires id and definition key.');
        }
    }
}
