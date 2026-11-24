<?php
declare(strict_types=1);
namespace Domains\Marketing\Application\Contract;

interface MarketingPaidReadRepository
{
    /** @param array<string,mixed> $verified @return array<string,mixed> */
    public function registerAccount(string $organizationId,string $credentialRef,array $verified):array;
    /** @return list<array<string,mixed>> */
    public function accounts(string $organizationId,int $limit=100):array;
    /** @return array<string,mixed>|null */
    public function account(string $organizationId,string $id):?array;
    /** @param array<string,list<array<string,mixed>>> $hierarchy @return array<string,int> */
    public function importHierarchy(string $organizationId,string $accountId,array $hierarchy):array;
    /** @param list<array<string,mixed>> $rows @return int */
    public function importInsights(string $organizationId,string $accountId,string $grain,string $from,string $to,array $rows):int;
    /** @return list<array<string,mixed>> */
    public function insights(string $organizationId,string $accountId,string $grain,string $from,string $to):array;
    /** @param array<string,list<array<string,mixed>>> $hierarchy @param list<array<string,mixed>> $insights @return array<string,int> */
    public function importSnapshot(string $organizationId,string $accountId,string $from,string $to,array $hierarchy,array $insights):array;
    /** @return array{campaigns:list<array<string,mixed>>,adsets:list<array<string,mixed>>,ads:list<array<string,mixed>>} */
    public function hierarchy(string $organizationId,string $accountId):array;
    /** Sanitize error codes; provider token/error text must never persist. */
    public function recordSyncFailure(string $organizationId,string $accountId,string $requestedBy,string $errorCode):void;
    /** @return list<array<string,mixed>> */
    public function syncHistory(string $organizationId,string $accountId,int $limit=20):array;
    /**
     * Authoritative tenant-owned Marketing DB readback only, no claim of external Meta verification.
     * @return array<string,mixed>
     */
    public function spendEvidence(string $organizationId,string $accountId,string $snapshotId):array;
    /** @return list<array<string,mixed>> */
    public function campaigns(string $organizationId,string $accountId,int $limit=100):array;
}
