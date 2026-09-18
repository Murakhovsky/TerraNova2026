<?php
declare(strict_types=1);

namespace Kernel\Observability;

use InvalidArgumentException;
use Kernel\Shared\Domain\OrganizationId;
use Kernel\Shared\Domain\UserId;

final readonly class ExecutionContext
{
    public function __construct(
        public CorrelationId $correlationId,
        public string $source,
        public ?OrganizationId $organizationId = null,
        public ?UserId $actorId = null,
    ) {
        if (trim($this->source) === '') {
            throw new InvalidArgumentException('Execution context source is required.');
        }
    }

    public function toLogContext(): array
    {
        return [
            'correlation_id' => $this->correlationId->value(),
            'source' => $this->source,
            'organization_id' => $this->organizationId?->value(),
            'actor_id' => $this->actorId?->value(),
        ];
    }
}
