<?php
declare(strict_types=1);

namespace Platform\Knowledge\Model;

use InvalidArgumentException;
use Kernel\Shared\Domain\OrganizationId;

final readonly class ContextRequest
{
    /** @param list<string> $knowledgeBaseIds @param list<string> $references @param array<string,mixed> $filters */
    public function __construct(
        public OrganizationId $organizationId,
        public string $query,
        public array $knowledgeBaseIds = [],
        public array $references = [],
        public int $maxTokens = 4000,
        public int $limit = 20,
        public array $filters = [],
        public ?string $correlationId = null,
    ) {
        if (trim($this->query) === '') {
            throw new InvalidArgumentException('Context request requires a query.');
        }
        if ($this->maxTokens < 1 || $this->limit < 1 || $this->limit > 100) {
            throw new InvalidArgumentException('Context request token budget/limit is invalid.');
        }
    }
}
