<?php
declare(strict_types=1);

namespace Domains\Sales\Application\Contract;

use DateTimeImmutable;

interface SalesHistoricalMetricsReadModelInterface
{
    /** @return list<array{currency:string,deal_count:int,pipeline_value:float,weighted_pipeline:float}> */
    public function pipelineMoney(string $organizationId, ?string $pipelineId = null): array;

    /** @return array{won:int,closed:int} */
    public function closedOutcomes(string $organizationId, DateTimeImmutable $from, DateTimeImmutable $to, ?string $pipelineId = null): array;

    /** @return array{won:int,created:int} */
    public function createdCohortOutcomes(string $organizationId, DateTimeImmutable $from, DateTimeImmutable $to, ?string $pipelineId = null): array;

    /** @return list<array{pipeline_id:?string,from_stage_code:string,to_stage_code:string,history_quality:string,transition_count:int}> */
    public function transitionFlow(string $organizationId, DateTimeImmutable $from, DateTimeImmutable $to, ?string $pipelineId = null): array;

    /** @return array{created:int,stages:list<array{pipeline_id:?string,stage_code:string,history_quality:string,reached_count:int}>} */
    public function cohortFunnel(string $organizationId, DateTimeImmutable $from, DateTimeImmutable $to, ?string $pipelineId = null): array;

    /** @return list<array{pipeline_id:?string,stage_code:string,history_quality:string,duration_seconds:int}> */
    public function stageDurationSamples(string $organizationId, DateTimeImmutable $from, DateTimeImmutable $to, ?string $pipelineId = null): array;

    /** @return list<array{pipeline_id:?string,deal_id:string,won_at:string,cycle_seconds:int}> */
    public function salesCycleSamples(string $organizationId, DateTimeImmutable $from, DateTimeImmutable $to, ?string $pipelineId = null): array;

    /** @return list<array<string,mixed>> */
    public function openDealRiskFacts(string $organizationId, DateTimeImmutable $asOf, ?string $pipelineId = null): array;
}
