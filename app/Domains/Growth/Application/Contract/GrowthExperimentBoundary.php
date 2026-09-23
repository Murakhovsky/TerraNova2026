<?php
declare(strict_types=1);

namespace Domains\Growth\Application\Contract;

interface GrowthExperimentBoundary
{
    /** @param array<string,mixed> $input @return array<string,mixed> */
    public function createExperiment(
        string $organizationId,int $actorId,string $correlationId,string $idempotencyKey,array $input
    ):array;

    /** @return array<string,mixed> */
    public function startExperiment(
        string $organizationId,int $actorId,string $correlationId,string $experimentId,string $idempotencyKey
    ):array;

    /** @return array<string,mixed> */
    public function pauseExperiment(
        string $organizationId,int $actorId,string $correlationId,string $experimentId,string $idempotencyKey
    ):array;

    /** @return array<string,mixed> */
    public function resumeExperiment(
        string $organizationId,int $actorId,string $correlationId,string $experimentId,string $idempotencyKey
    ):array;

    /** @return array<string,mixed> */
    public function completeExperiment(
        string $organizationId,int $actorId,string $correlationId,string $experimentId,string $idempotencyKey
    ):array;

    /** @return array<string,mixed> */
    public function archiveExperiment(
        string $organizationId,int $actorId,string $correlationId,string $experimentId,string $idempotencyKey
    ):array;

    /** @return array<string,mixed> */
    public function assignCandidate(
        string $organizationId,int $actorId,string $correlationId,string $experimentId,string $candidateId,
        ?string $variantKey,string $idempotencyKey
    ):array;

    /** @return array<string,mixed> */
    public function experimentBrief(string $organizationId,string $experimentId):array;

    /** @param array<string,mixed> $filters @return list<array<string,mixed>> */
    public function experiments(string $organizationId,array $filters=[],int $limit=100):array;
}
