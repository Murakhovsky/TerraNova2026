<?php
declare(strict_types=1);

namespace Domains\Growth\Application\Contract;

use Domains\Growth\Domain\GrowthMarketUniverse;

interface GrowthMarketDiscoveryRepositoryInterface
{
    public function createUniverse(GrowthMarketUniverse $universe,int $actorId):void;
    public function lockUniverse(string $organizationId,string $universeId):GrowthMarketUniverse;
    public function updateUniverse(GrowthMarketUniverse $universe,int $actorId):void;
    /** @return array<string,mixed>|null */
    public function viewUniverse(string $organizationId,string $universeId):?array;
    /** @return list<array<string,mixed>> */
    public function listUniverses(string $organizationId,int $limit=200):array;
    /** @return list<array{organization_id:string,universe_id:string}> */
    public function schedulerUniverses(int $limit=500):array;
    public function updateRuntime(string $organizationId,string $universeId,?string $cursor):void;

    public function createRun(string $organizationId,string $runId,string $universeId,int $requestedLimit,int $actorId):void;
    public function completeRun(
        string $organizationId,string $runId,string $status,int $collectedCount,int $accountCount,
        int $existingCount,int $monitoredCount,int $opportunityCount,?string $nextCursor,?string $errorSummary
    ):void;
    /** @return array<string,mixed>|null */
    public function viewRun(string $organizationId,string $runId):?array;
    /** @return list<array<string,mixed>> */
    public function latestRuns(string $organizationId,string $universeId,int $limit=20):array;

    public function upsertMembership(
        string $organizationId,string $universeId,string $accountId,string $externalKeyHash,
        int $fitScore,string $status,string $sourceReference
    ):void;
    /** @return list<array<string,mixed>> */
    public function membershipsForAccount(string $organizationId,string $accountId):array;
    /** @return list<array<string,mixed>> */
    public function membershipsForUniverse(string $organizationId,string $universeId,int $limit=200):array;
    public function claimOpportunityTrigger(string $organizationId,string $universeId,string $accountId,string $signalId):string;
    public function setMembershipCandidate(string $organizationId,string $universeId,string $accountId,string $candidateId):void;
}
