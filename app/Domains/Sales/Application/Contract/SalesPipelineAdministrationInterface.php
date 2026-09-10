<?php
declare(strict_types=1);

namespace Domains\Sales\Application\Contract;

interface SalesPipelineAdministrationInterface
{
    /** @return list<array<string, mixed>> */
    public function pipelines(string $organizationId): array;

    /** @return array<string, mixed>|null */
    public function pipeline(string $organizationId, string $pipelineId): ?array;

    /** @return array<string, mixed> */
    public function createPipeline(string $organizationId, array $input, string $actorId): array;

    /** @return array<string, mixed> */
    public function updatePipeline(string $organizationId, string $pipelineId, array $input, string $actorId): array;

    /** @return array<string, mixed> */
    public function createStage(string $organizationId, string $pipelineId, array $input, string $actorId): array;

    /** @return array<string, mixed> */
    public function updateStage(string $organizationId, string $stageId, array $input, string $actorId): array;

    public function reorderStages(string $organizationId, string $pipelineId, array $items, int $version, string $actorId): void;

    public function replaceTransitions(string $organizationId, string $pipelineId, array $transitions, int $version, string $actorId): void;

    /** @return list<array<string, mixed>> */
    public function lostReasons(string $organizationId, string $pipelineId): array;

    /** @return array<string, mixed> */
    public function createLostReason(string $organizationId, string $pipelineId, array $input, string $actorId): array;

    /** @return array<string, mixed> */
    public function updateLostReason(string $organizationId, string $reasonId, array $input, string $actorId): array;

    /** @return array{valid:bool,errors:list<string>,warnings:list<string>} */
    public function validatePipeline(string $organizationId, string $pipelineId): array;
}
