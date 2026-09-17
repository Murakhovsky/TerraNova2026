<?php
declare(strict_types=1);

namespace Platform\Knowledge\Model;

use InvalidArgumentException;
use Kernel\Shared\Domain\OrganizationId;

final readonly class Source
{
    /** @param array<string,mixed> $metadata */
    public function __construct(
        public string $id,
        public OrganizationId $organizationId,
        public string $type,
        public string $locator,
        public ?string $provider = null,
        public ?string $connectionId = null,
        public array $metadata = [],
    ) {
        if (trim($this->id) === '' || trim($this->type) === '' || trim($this->locator) === '') {
            throw new InvalidArgumentException('Knowledge source requires id, type and locator.');
        }
    }
}
