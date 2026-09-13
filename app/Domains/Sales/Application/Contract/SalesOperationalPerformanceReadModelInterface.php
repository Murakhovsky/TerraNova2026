<?php
declare(strict_types=1);

namespace Domains\Sales\Application\Contract;

use DateTimeImmutable;

interface SalesOperationalPerformanceReadModelInterface
{
    /**
     * Returns the last communication before the period for each Deal/channel plus all
     * communications inside the period. The pre-period row prevents an already-open
     * inbound burst from being falsely counted as a new response opportunity.
     *
     * @return list<array<string,mixed>>
     */
    public function communicationTimeline(
        string $organizationId,
        DateTimeImmutable $from,
        DateTimeImmutable $to,
        ?string $pipelineId = null,
    ): array;

    /** @return list<array<string,mixed>> */
    public function followupFacts(
        string $organizationId,
        DateTimeImmutable $from,
        DateTimeImmutable $to,
        ?string $pipelineId = null,
    ): array;

    /** @return list<array<string,mixed>> */
    public function terminalOutcomeFacts(
        string $organizationId,
        DateTimeImmutable $from,
        DateTimeImmutable $to,
        ?string $pipelineId = null,
    ): array;
}
