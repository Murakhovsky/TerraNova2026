<?php
declare(strict_types=1);

namespace Platform\Knowledge\Model;

use InvalidArgumentException;
use Kernel\Shared\Domain\OrganizationId;

final readonly class KnowledgeBase
{
    /** @param list<string> $sourceIds @param array<string,mixed> $metadata */
    public function __construct(
        public string $id,
        public OrganizationId $organizationId,
        public string $name,
        public array $sourceIds = [],
        public ?string $description = null,
        public array $metadata = [],
    ) {
        if (trim($this->id) === '' || trim($this->name) === '') {
            throw new InvalidArgumentException('Knowledge base requires id and name.');
        }
        if (count(array_unique($this->sourceIds)) !== count($this->sourceIds)) {
            throw new InvalidArgumentException('Knowledge base source ids must be unique.');
        }
    }
}
