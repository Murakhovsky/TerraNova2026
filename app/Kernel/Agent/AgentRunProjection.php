<?php

declare(strict_types=1);

namespace Kernel\Agent;

use DateTimeImmutable;
use Kernel\Agent\Model\AgentRunStatus;

final readonly class AgentRunProjection
{
    /**
     * @param array<string,mixed> $output
     * @param array<string,mixed> $contextReference
     */
    public function __construct(
        public string $id,
        public string $organizationId,
        public string $agentName,
        public string $agentVersion,
        public ?string $subjectType,
        public ?string $subjectId,
        public AgentRunStatus $status,
        public array $output,
        public array $contextReference,
        public ?float $confidence,
        public string $provider,
        public string $model,
        public ?int $inputTokens,
        public ?int $outputTokens,
        public ?float $costAmount,
        public ?string $costCurrency,
        public ?int $durationMs,
        public ?string $error,
        public string $correlationId,
        public ?DateTimeImmutable $startedAt,
        public ?DateTimeImmutable $finishedAt,
        public DateTimeImmutable $createdAt,
    ) {
    }
}
