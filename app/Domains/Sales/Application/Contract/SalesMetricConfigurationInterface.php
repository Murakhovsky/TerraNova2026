<?php
declare(strict_types=1);

namespace Domains\Sales\Application\Contract;

interface SalesMetricConfigurationInterface
{
    /** @return list<array{id:string,pipeline_id:string,code:string,stuck_after_seconds:?int}> */
    public function stageThresholds(string $organizationId, ?string $pipelineId = null): array;

    public function setStageStuckThreshold(
        string $organizationId,
        string $stageId,
        ?int $stuckAfterSeconds,
        string $actorId = 'system',
    ): void;
}
