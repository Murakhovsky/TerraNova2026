<?php

declare(strict_types=1);

namespace Kernel\Agent;

use DateTimeImmutable;
use Kernel\Action\ActionStatus;

final readonly class AgentActionProjection
{
    /** @param array<string,mixed> $parameters */
    public function __construct(
        public string $id,
        public string $organizationId,
        public string $runId,
        public string $type,
        public ?string $targetType,
        public ?string $targetId,
        public array $parameters,
        public ActionStatus $status,
        public string $executionMode,
        public string $riskLevel,
        public DateTimeImmutable $createdAt,
        public ?string $approvalId = null,
        public ?string $approvalStatus = null,
    ) {
    }
}
