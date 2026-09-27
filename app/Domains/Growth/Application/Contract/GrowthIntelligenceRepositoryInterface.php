<?php
declare(strict_types=1);

namespace Domains\Growth\Application\Contract;

use Domains\Growth\Domain\AccountIcpMatch;
use Domains\Growth\Domain\AccountSnapshot;
use Domains\Growth\Domain\GrowthAccount;
use Domains\Growth\Domain\IcpProfile;

interface GrowthIntelligenceRepositoryInterface
{
    public function createIcpProfile(IcpProfile $profile,int $actorId): void;
    public function lockIcpProfile(string $organizationId,string $profileId,int $revision): IcpProfile;
    public function updateIcpProfile(IcpProfile $profile,int $actorId): void;
    public function archiveOtherActiveIcpRevisions(string $organizationId,string $profileId,int $exceptRevision,int $actorId): void;
    /** @return array<string,mixed>|null */
    public function viewIcpProfile(string $organizationId,string $profileId,int $revision): ?array;

    public function createAccount(GrowthAccount $account,int $actorId): void;
    /** @return array<string,mixed>|null */
    public function findAccountByDomain(string $organizationId,string $canonicalDomain): ?array;
    /** @return array<string,mixed>|null */
    public function viewAccount(string $organizationId,string $accountId): ?array;

    public function createSnapshot(AccountSnapshot $snapshot,int $actorId): void;
    public function viewSnapshot(string $organizationId,string $snapshotId): ?AccountSnapshot;
    public function latestSnapshot(string $organizationId,string $accountId): ?AccountSnapshot;

    public function createIcpMatch(string $matchId,string $organizationId,AccountIcpMatch $match,int $actorId): void;
    /** @return array<string,mixed>|null */
    public function viewIcpMatch(string $organizationId,string $matchId): ?array;
    /** @return array<string,mixed>|null */
    public function latestIcpMatch(string $organizationId,string $accountId): ?array;
}
