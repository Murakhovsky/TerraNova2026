<?php
declare(strict_types=1);

namespace Platform\Search\Model;

use InvalidArgumentException;
use Kernel\Shared\Domain\OrganizationId;

final readonly class SearchQuery
{
    /**
     * @param list<string> $scopes
     * @param array<string, scalar|list<scalar>|null> $filters
     */
    public function __construct(
        public OrganizationId $organizationId,
        public string $text,
        public array $scopes = [],
        public array $filters = [],
        public int $limit = 20,
        public int $offset = 0,
    ) {
        if (trim($this->text) === '') {
            throw new InvalidArgumentException('Search query text cannot be empty.');
        }
        if ($this->limit < 1 || $this->limit > 100) {
            throw new InvalidArgumentException('Search query limit must be between 1 and 100.');
        }
        if ($this->offset < 0) {
            throw new InvalidArgumentException('Search query offset cannot be negative.');
        }
    }
}
