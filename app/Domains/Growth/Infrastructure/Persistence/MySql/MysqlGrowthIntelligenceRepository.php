<?php
declare(strict_types=1);

namespace Domains\Growth\Infrastructure\Persistence\MySql;

use DateTimeImmutable;
use Domains\Growth\Application\Contract\GrowthIntelligenceRepositoryInterface;
use Domains\Growth\Domain\AccountIcpMatch;
use Domains\Growth\Domain\AccountSnapshot;
use Domains\Growth\Domain\GrowthAccount;
use Domains\Growth\Domain\IcpCriteria;
use Domains\Growth\Domain\IcpProfile;
use Domains\Growth\Domain\IcpProfileStatus;
use InvalidArgumentException;
use Kernel\Shared\Domain\OrganizationId;
use PDO;

final readonly class MysqlGrowthIntelligenceRepository implements GrowthIntelligenceRepositoryInterface
{
    public function __construct(private PDO $connection) {}

    public function createIcpProfile(IcpProfile $profile,int $actorId): void
    {
        $this->execute(
            'INSERT INTO tn_growth_icp_profiles
             (organization_id,profile_id,revision,name,status,criteria_json,created_by,updated_by)
             VALUES(:organization_id,:profile_id,:revision,:name,:status,:criteria_json,:created_by,:updated_by)',
            [
                'organization_id'=>$profile->organizationId->value(),'profile_id'=>$profile->id,'revision'=>$profile->revision,
                'name'=>$profile->name,'status'=>$profile->status()->value,'criteria_json'=>$this->encode($profile->criteria->toArray()),
                'created_by'=>$actorId,'updated_by'=>$actorId,
            ],
        );
    }

    public function lockIcpProfile(string $organizationId,string $profileId,int $revision): IcpProfile
    {
        $row=$this->one(
            'SELECT organization_id,profile_id,revision,name,status,criteria_json
             FROM tn_growth_icp_profiles
             WHERE organization_id=:organization_id AND profile_id=:profile_id AND revision=:revision
             LIMIT 1 FOR UPDATE',
            ['organization_id'=>$organizationId,'profile_id'=>$profileId,'revision'=>$revision],
        );
        if($row===null)throw new InvalidArgumentException('Growth ICP profile was not found.');
        return $this->hydrateProfile($row);
    }

    public function updateIcpProfile(IcpProfile $profile,int $actorId): void
    {
        $activated=$profile->status()===IcpProfileStatus::Active?'NOW(6)':'activated_at';
        $sql='UPDATE tn_growth_icp_profiles
              SET status=:status,updated_by=:updated_by,updated_at=NOW(6),activated_at='.$activated.'
              WHERE organization_id=:organization_id AND profile_id=:profile_id AND revision=:revision';
        $statement=$this->connection->prepare($sql);
        $statement->execute([
            'status'=>$profile->status()->value,'updated_by'=>$actorId,'organization_id'=>$profile->organizationId->value(),
            'profile_id'=>$profile->id,'revision'=>$profile->revision,
        ]);
        if($statement->rowCount()!==1)throw new InvalidArgumentException('Growth ICP update did not change exactly one row.');
    }

    public function archiveOtherActiveIcpRevisions(string $organizationId,string $profileId,int $exceptRevision,int $actorId): void
    {
        $this->execute(
            'UPDATE tn_growth_icp_profiles
             SET status=\'archived\',archived_at=NOW(6),updated_by=:updated_by,updated_at=NOW(6)
             WHERE organization_id=:organization_id AND profile_id=:profile_id
               AND revision<>:except_revision AND status=\'active\'',
            [
                'updated_by'=>$actorId,'organization_id'=>$organizationId,
                'profile_id'=>$profileId,'except_revision'=>$exceptRevision,
            ],
        );
    }

    public function viewIcpProfile(string $organizationId,string $profileId,int $revision): ?array
    {
        $row=$this->one(
            'SELECT organization_id,profile_id,revision,name,status,criteria_json,activated_at,archived_at,
                    created_by,updated_by,created_at,updated_at
             FROM tn_growth_icp_profiles
             WHERE organization_id=:organization_id AND profile_id=:profile_id AND revision=:revision LIMIT 1',
            ['organization_id'=>$organizationId,'profile_id'=>$profileId,'revision'=>$revision],
        );
        if($row===null)return null;
        $row['criteria']=$this->decodeObject((string)$row['criteria_json']);
        unset($row['criteria_json']);
        return $row;
    }

    public function createAccount(GrowthAccount $account,int $actorId): void
    {
        $this->execute(
            'INSERT INTO tn_growth_accounts(organization_id,account_id,name,canonical_domain,created_by,updated_by)
             VALUES(:organization_id,:account_id,:name,:canonical_domain,:created_by,:updated_by)',
            [
                'organization_id'=>$account->organizationId->value(),'account_id'=>$account->id,
                'name'=>$account->name,'canonical_domain'=>strtolower($account->canonicalDomain),
                'created_by'=>$actorId,'updated_by'=>$actorId,
            ],
        );
    }

    public function findAccountByDomain(string $organizationId,string $canonicalDomain): ?array
    {
        return $this->one(
            'SELECT organization_id,account_id,name,canonical_domain,created_by,updated_by,created_at,updated_at
             FROM tn_growth_accounts
             WHERE organization_id=:organization_id AND canonical_domain=:canonical_domain LIMIT 1',
            ['organization_id'=>$organizationId,'canonical_domain'=>strtolower($canonicalDomain)],
        );
    }

    public function viewAccount(string $organizationId,string $accountId): ?array
    {
        return $this->one(
            'SELECT organization_id,account_id,name,canonical_domain,created_by,updated_by,created_at,updated_at
             FROM tn_growth_accounts
             WHERE organization_id=:organization_id AND account_id=:account_id LIMIT 1',
            ['organization_id'=>$organizationId,'account_id'=>$accountId],
        );
    }

    public function createSnapshot(AccountSnapshot $snapshot,int $actorId): void
    {
        $this->execute(
            'INSERT INTO tn_growth_account_snapshots
             (organization_id,snapshot_id,account_id,firmographics_json,technologies_json,hiring_json,recent_changes_json,
              signal_types_json,source_references_json,observed_at,captured_at,created_by)
             VALUES(:organization_id,:snapshot_id,:account_id,:firmographics_json,:technologies_json,:hiring_json,:recent_changes_json,
                    :signal_types_json,:source_references_json,:observed_at,:captured_at,:created_by)',
            [
                'organization_id'=>$snapshot->organizationId->value(),'snapshot_id'=>$snapshot->id,'account_id'=>$snapshot->accountId,
                'firmographics_json'=>$this->encode($snapshot->firmographics),'technologies_json'=>$this->encode($snapshot->technologies),
                'hiring_json'=>$this->encode($snapshot->hiring),'recent_changes_json'=>$this->encode($snapshot->recentChanges),
                'signal_types_json'=>$this->encode($snapshot->signalTypes),'source_references_json'=>$this->encode($snapshot->sourceReferences),
                'observed_at'=>$snapshot->observedAt->format('Y-m-d H:i:s.u'),'captured_at'=>$snapshot->capturedAt->format('Y-m-d H:i:s.u'),
                'created_by'=>$actorId,
            ],
        );
    }

    public function viewSnapshot(string $organizationId,string $snapshotId): ?AccountSnapshot
    {
        $row=$this->one(
            'SELECT organization_id,snapshot_id,account_id,firmographics_json,technologies_json,hiring_json,recent_changes_json,
                    signal_types_json,source_references_json,observed_at,captured_at
             FROM tn_growth_account_snapshots
             WHERE organization_id=:organization_id AND snapshot_id=:snapshot_id LIMIT 1',
            ['organization_id'=>$organizationId,'snapshot_id'=>$snapshotId],
        );
        return $row===null?null:$this->hydrateSnapshot($row);
    }

    public function latestSnapshot(string $organizationId,string $accountId): ?AccountSnapshot
    {
        $row=$this->one(
            'SELECT organization_id,snapshot_id,account_id,firmographics_json,technologies_json,hiring_json,recent_changes_json,
                    signal_types_json,source_references_json,observed_at,captured_at
             FROM tn_growth_account_snapshots
             WHERE organization_id=:organization_id AND account_id=:account_id
             ORDER BY captured_at DESC,snapshot_id DESC LIMIT 1',
            ['organization_id'=>$organizationId,'account_id'=>$accountId],
        );
        if($row===null)return null;
        return $this->hydrateSnapshot($row);
    }

    public function createIcpMatch(string $matchId,string $organizationId,AccountIcpMatch $match,int $actorId): void
    {
        $this->execute(
            'INSERT INTO tn_growth_account_icp_matches
             (organization_id,match_id,account_id,profile_id,profile_revision,fit_score,fit_json,matched_json,gaps_json,scored_at,created_by)
             VALUES(:organization_id,:match_id,:account_id,:profile_id,:profile_revision,:fit_score,:fit_json,:matched_json,:gaps_json,:scored_at,:created_by)',
            [
                'organization_id'=>$organizationId,'match_id'=>$matchId,'account_id'=>$match->accountId,
                'profile_id'=>$match->profileId,'profile_revision'=>$match->profileRevision,'fit_score'=>$match->fit->score,
                'fit_json'=>$this->encode($match->fit->toArray()),'matched_json'=>$this->encode($match->matchedGroups),
                'gaps_json'=>$this->encode($match->gaps),'scored_at'=>$match->scoredAt->format('Y-m-d H:i:s.u'),'created_by'=>$actorId,
            ],
        );
    }

    public function viewIcpMatch(string $organizationId,string $matchId): ?array
    {
        $row=$this->one(
            'SELECT organization_id,match_id,account_id,profile_id,profile_revision,fit_score,fit_json,matched_json,gaps_json,
                    scored_at,created_by,created_at
             FROM tn_growth_account_icp_matches
             WHERE organization_id=:organization_id AND match_id=:match_id LIMIT 1',
            ['organization_id'=>$organizationId,'match_id'=>$matchId],
        );
        return $this->hydrateMatch($row);
    }

    public function latestIcpMatch(string $organizationId,string $accountId): ?array
    {
        $row=$this->one(
            'SELECT organization_id,match_id,account_id,profile_id,profile_revision,fit_score,fit_json,matched_json,gaps_json,
                    scored_at,created_by,created_at
             FROM tn_growth_account_icp_matches
             WHERE organization_id=:organization_id AND account_id=:account_id
             ORDER BY scored_at DESC,match_id DESC LIMIT 1',
            ['organization_id'=>$organizationId,'account_id'=>$accountId],
        );
        return $this->hydrateMatch($row);
    }

    /** @param array<string,mixed> $row */
    private function hydrateSnapshot(array $row): AccountSnapshot
    {
        return new AccountSnapshot(
            (string)$row['snapshot_id'],OrganizationId::fromString((string)$row['organization_id']),(string)$row['account_id'],
            $this->decodeObject((string)$row['firmographics_json']),
            $this->decodeList((string)$row['technologies_json']),
            $this->decodeList((string)$row['hiring_json']),
            $this->decodeList((string)$row['recent_changes_json']),
            $this->decodeList((string)$row['signal_types_json']),
            $this->decodeList((string)$row['source_references_json']),
            new DateTimeImmutable((string)$row['observed_at']),new DateTimeImmutable((string)$row['captured_at']),
        );
    }

    /** @param array<string,mixed>|null $row @return array<string,mixed>|null */
    private function hydrateMatch(?array $row): ?array
    {
        if($row===null)return null;
        $row['fit']=$this->decodeObject((string)$row['fit_json']);
        $row['matched_groups']=$this->decodeList((string)$row['matched_json']);
        $row['gaps']=$this->decodeList((string)$row['gaps_json']);
        unset($row['fit_json'],$row['matched_json'],$row['gaps_json']);
        $row['fit_score']=(int)$row['fit_score'];
        $row['profile_revision']=(int)$row['profile_revision'];
        return $row;
    }

    /** @param array<string,mixed> $row */
    private function hydrateProfile(array $row): IcpProfile
    {
        $status=IcpProfileStatus::tryFrom((string)$row['status'])
            ?? throw new InvalidArgumentException('Stored Growth ICP status is invalid.');
        return IcpProfile::restore(
            (string)$row['profile_id'],OrganizationId::fromString((string)$row['organization_id']),(int)$row['revision'],
            (string)$row['name'],IcpCriteria::fromArray($this->decodeObject((string)$row['criteria_json'])),$status,
        );
    }

    private function execute(string $sql,array $params): void
    {
        $statement=$this->connection->prepare($sql);
        $statement->execute($params);
    }

    private function one(string $sql,array $params): ?array
    {
        $statement=$this->connection->prepare($sql);
        $statement->execute($params);
        $row=$statement->fetch(PDO::FETCH_ASSOC);
        return $row===false?null:$row;
    }

    private function encode(array $value): string
    {
        return json_encode($value,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_PRESERVE_ZERO_FRACTION);
    }

    /** @return array<string,mixed> */
    private function decodeObject(string $json): array
    {
        $value=json_decode($json,true,512,JSON_THROW_ON_ERROR);
        if(!is_array($value)||array_is_list($value))throw new InvalidArgumentException('Stored Growth JSON object is invalid.');
        return $value;
    }

    /** @return list<string> */
    private function decodeList(string $json): array
    {
        $value=json_decode($json,true,512,JSON_THROW_ON_ERROR);
        if(!is_array($value)||!array_is_list($value))throw new InvalidArgumentException('Stored Growth JSON list is invalid.');
        return array_values(array_map('strval',$value));
    }
}
