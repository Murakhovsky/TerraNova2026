<?php
declare(strict_types=1);

namespace Platform\Audit\Model;

use DateTimeImmutable;
use InvalidArgumentException;
use Kernel\Shared\Domain\OrganizationId;

final readonly class ActivityRecord
{
    /** @param array<string,mixed> $input @param array<string,mixed> $output @param array<string,mixed> $metadata */
    public function __construct(
        public string $id,
        public OrganizationId $organizationId,
        public Actor $actor,
        public string $action,
        public ResourceReference $resource,
        public array $input,
        public array $output,
        public ?string $agent,
        public ?string $tool,
        public ?string $workflow,
        public ?int $durationMs,
        public ?float $cost,
        public ?string $costUnit,
        public ActivityStatus $status,
        public ?string $error,
        public string $correlationId,
        public DateTimeImmutable $timestamp,
        public array $metadata = [],
    ) {
        if (trim($this->id) === '' || trim($this->action) === '' || trim($this->correlationId) === '') {
            throw new InvalidArgumentException('Activity record requires id, action and correlation id.');
        }
        if ($this->durationMs !== null && $this->durationMs < 0) {
            throw new InvalidArgumentException('Audit duration cannot be negative.');
        }
        if ($this->cost !== null && $this->cost < 0) {
            throw new InvalidArgumentException('Audit cost cannot be negative.');
        }
        if ($this->cost !== null && ($this->costUnit === null || trim($this->costUnit) === '')) {
            throw new InvalidArgumentException('Audit cost requires a unit.');
        }
    }

    /** @return array<string,mixed> */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'organization_id' => $this->organizationId->value(),
            'actor' => $this->actor->toArray(),
            'action' => $this->action,
            'resource' => $this->resource->toArray(),
            'input' => $this->input,
            'output' => $this->output,
            'agent' => $this->agent,
            'tool' => $this->tool,
            'workflow' => $this->workflow,
            'duration_ms' => $this->durationMs,
            'cost' => $this->cost,
            'cost_unit' => $this->costUnit,
            'status' => $this->status->value,
            'error' => $this->error,
            'correlation_id' => $this->correlationId,
            'timestamp' => $this->timestamp->format(DATE_ATOM),
            'metadata' => $this->metadata,
        ];
    }
}
