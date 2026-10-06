<?php
declare(strict_types=1);

namespace Domains\CapitalMarkets\Application\Contract;

interface TokenizedEquityScannerRepositoryInterface
{
    /** @param array<string,mixed> $config */
    public function saveTarget(
        string $organizationId,string $targetId,string $hypothesis,bool $enabled,int $priority,array $config
    ):void;

    /** @return list<array<string,mixed>> */
    public function listTargets(string $organizationId,bool $enabledOnly=false,int $limit=500):array;

    /** @return list<string> */
    public function schedulerOrganizations(int $limit=500):array;

    /** @return array<string,mixed>|null */
    public function getRunByIdempotencyKey(string $organizationId,string $idempotencyKey):?array;

    public function claimRun(
        string $organizationId,string $runId,string $idempotencyKey,string $trigger,string $startedAt
    ):bool;

    /** @param array<string,mixed> $result */
    public function saveRun(
        string $organizationId,string $runId,string $idempotencyKey,string $trigger,string $status,
        int $targetCount,int $completedCount,int $failedCount,array $result,string $startedAt,string $completedAt
    ):void;

    /** @return list<array<string,mixed>> */
    public function listRuns(string $organizationId,int $limit=100):array;
}
