<?php
declare(strict_types=1);

namespace Platform\Knowledge\Model;

use DateTimeImmutable;
use Kernel\Shared\Domain\OrganizationId;

final readonly class Context
{
    /** @param list<RetrievalResult> $results @param array<string,mixed> $metadata */
    public function __construct(
        public OrganizationId $organizationId,
        public string $query,
        public array $results,
        public int $tokenCount,
        public DateTimeImmutable $builtAt,
        public array $metadata = [],
    ) {
    }

    /** @return array<string,mixed> */
    public function toArray(): array
    {
        return [
            'query' => $this->query,
            'token_count' => $this->tokenCount,
            'sources' => array_map(static fn (RetrievalResult $result): array => $result->toArray(), $this->results),
            'metadata' => $this->metadata,
        ];
    }
}
