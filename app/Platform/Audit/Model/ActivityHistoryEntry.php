<?php
declare(strict_types=1);

namespace Platform\Audit\Model;

use DateTimeImmutable;
use Kernel\Shared\Domain\OrganizationId;

final readonly class ActivityHistoryEntry
{
    /** @param array<string,mixed> $input @param array<string,mixed> $changes @param array<string,mixed> $result @param array<string,mixed> $metadata */
    public function __construct(
        public string $id,
        public OrganizationId $organizationId,
        public string $category,
        public Actor $actor,
        public ActivitySource $source,
        public ResourceReference $resource,
        public ?string $action,
        public ?string $reason,
        public array $input,
        public array $changes,
        public array $result,
        public string $correlationId,
        public DateTimeImmutable $timestamp,
        public array $metadata = [],
    ) {
    }

    /** @return array<string,mixed> */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'organization_id' => $this->organizationId->value(),
            'category' => $this->category,
            'actor' => $this->actor->toArray(),
            'source' => $this->source->value,
            'resource' => $this->resource->toArray(),
            'action' => $this->action,
            'reason' => $this->reason,
            'input' => $this->input,
            'changes' => $this->changes,
            'result' => $this->result,
            'correlation_id' => $this->correlationId,
            'timestamp' => $this->timestamp->format(DATE_ATOM),
            'metadata' => $this->metadata,
        ];
    }
}
