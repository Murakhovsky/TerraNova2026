<?php
declare(strict_types=1);

namespace Domains\Growth\Infrastructure\Persistence\MySql;

use Domains\Growth\Application\Contract\GrowthMarketDiscoveryRepositoryInterface;
use Domains\Growth\Domain\GrowthMarketUniverse;
use Domains\Growth\Domain\GrowthMode;
use Domains\Growth\Domain\OpportunityType;
use InvalidArgumentException;
use Kernel\Shared\Domain\OrganizationId;
use PDO;

final readonly class MysqlGrowthMarketDiscoveryRepository implements GrowthMarketDiscoveryRepositoryInterface
{
    public function __construct(private PDO $connection){}

    public function createUniverse(GrowthMarketUniverse $universe,int $actorId):void
    {
        $data=$universe->toArray();
        $this->execute(
            'INSERT INTO tn_growth_market_universes
             (organization_id,universe_id,name,source_type,source_url,source_url_hash,auth_mode,credential_reference,api_key_header,
              profile_id,profile_revision,min_icp_fit,opportunity_type,growth_mode,target_domain,enabled,created_by,updated_by)
             VALUES(:organization_id,:universe_id,:name,:source_type,:source_url,:source_url_hash,:auth_mode,:credential_reference,:api_key_header,
                    :profile_id,:profile_revision,:min_icp_fit,:opportunity_type,:growth_mode,:target_domain,:enabled,:created_by,:updated_by)',
            [
                'organization_id'=>$data['organization_id'],'universe_id'=>$data['universe_id'],'name'=>$data['name'],
                'source_type'=>$data['source_type'],'source_url'=>$data['url'],'source_url_hash'=>hash('sha256',(string)$data['url']),
                'auth_mode'=>$data['auth_mode'],'credential_reference'=>$data['credential_reference'],'api_key_header'=>$data['api_key_header'],
                'profile_id'=>$data['profile_id'],'profile_revision'=>$data['profile_revision'],'min_icp_fit'=>$data['min_icp_fit'],
                'opportunity_type'=>$data['opportunity_type'],'growth_mode'=>$data['growth_mode'],'target_domain'=>$data['target_domain'],
                'enabled'=>$data['enabled']?1:0,'created_by'=>$actorId,'updated_by'=>$actorId,
            ],
        );
    }

    public function lockUniverse(string $organizationId,string $universeId):GrowthMarketUniverse
    {
        $row=$this->one(
            'SELECT organization_id,universe_id,name,source_type,source_url,auth_mode,credential_reference,api_key_header,
                    profile_id,profile_revision,min_icp_fit,opportunity_type,growth_mode,target_domain,enabled
             FROM tn_growth_market_universes WHERE organization_id=:organization_id AND universe_id=:universe_id LIMIT 1 FOR UPDATE',
            ['organization_id'=>$organizationId,'universe_id'=>$universeId],
        );
        if($row===null)throw new InvalidArgumentException('Growth Market Universe was not found.');
        return $this->hydrate($row);
    }

    public function updateUniverse(GrowthMarketUniverse $universe,int $actorId):void
    {
        $statement=$this->connection->prepare(
            'UPDATE tn_growth_market_universes SET enabled=:enabled,updated_by=:updated_by,updated_at=NOW(6)
             WHERE organization_id=:organization_id AND universe_id=:universe_id'
        );
        $statement->execute([
            'enabled'=>$universe->enabled()?1:0,'updated_by'=>$actorId,
            'organization_id'=>$universe->organizationId->value(),'universe_id'=>$universe->id,
        ]);
        if($statement->rowCount()>1)throw new InvalidArgumentException('Growth Market Universe update changed too many rows.');
    }

    public function viewUniverse(string $organizationId,string $universeId):?array
    {
        return $this->normalized($this->one(
            'SELECT u.*,
                (SELECT COUNT(*) FROM tn_growth_market_memberships m WHERE m.organization_id=u.organization_id AND m.universe_id=u.universe_id) member_count,
                (SELECT COUNT(*) FROM tn_growth_market_memberships m WHERE m.organization_id=u.organization_id AND m.universe_id=u.universe_id AND m.status=\'opportunity\') opportunity_count,
                (SELECT r.status FROM tn_growth_market_discovery_runs r WHERE r.organization_id=u.organization_id AND r.universe_id=u.universe_id ORDER BY r.started_at DESC LIMIT 1) latest_run_status
             FROM tn_growth_market_universes u
             WHERE u.organization_id=:organization_id AND u.universe_id=:universe_id LIMIT 1',
            ['organization_id'=>$organizationId,'universe_id'=>$universeId],
        ));
    }

    public function listUniverses(string $organizationId,int $limit=200):array
    {
        $limit=max(1,min(500,$limit));
        $statement=$this->connection->prepare(
            'SELECT u.*,
                (SELECT COUNT(*) FROM tn_growth_market_memberships m WHERE m.organization_id=u.organization_id AND m.universe_id=u.universe_id) member_count,
                (SELECT COUNT(*) FROM tn_growth_market_memberships m WHERE m.organization_id=u.organization_id AND m.universe_id=u.universe_id AND m.status=\'opportunity\') opportunity_count,
                (SELECT r.status FROM tn_growth_market_discovery_runs r WHERE r.organization_id=u.organization_id AND r.universe_id=u.universe_id ORDER BY r.started_at DESC LIMIT 1) latest_run_status
             FROM tn_growth_market_universes u
             WHERE u.organization_id=:organization_id
             ORDER BY u.updated_at DESC,u.universe_id LIMIT '.$limit
        );
        $statement->execute(['organization_id'=>$organizationId]);
        return array_map(fn(array $row):array=>$this->normalized($row)??[],$statement->fetchAll(PDO::FETCH_ASSOC)?:[]);
    }

    public function schedulerUniverses(int $limit=500):array
    {
        $limit=max(1,min(1000,$limit));
        $rows=$this->connection->query(
            'SELECT organization_id,universe_id FROM tn_growth_market_universes WHERE enabled=1 ORDER BY updated_at,universe_id LIMIT '.$limit
        )->fetchAll(PDO::FETCH_ASSOC)?:[];
        return array_values(array_map(static fn(array $row):array=>[
            'organization_id'=>(string)$row['organization_id'],'universe_id'=>(string)$row['universe_id'],
        ],$rows));
    }

    public function updateRuntime(string $organizationId,string $universeId,?string $cursor):void
    {
        $statement=$this->connection->prepare(
            'UPDATE tn_growth_market_universes SET cursor=:cursor,last_run_at=NOW(6),updated_at=NOW(6)
             WHERE organization_id=:organization_id AND universe_id=:universe_id'
        );
        $statement->execute(['cursor'=>$cursor,'organization_id'=>$organizationId,'universe_id'=>$universeId]);
        if($statement->rowCount()>1)throw new InvalidArgumentException('Growth Market Universe runtime update changed too many rows.');
    }

    public function createRun(string $organizationId,string $runId,string $universeId,int $requestedLimit,int $actorId):void
    {
        $this->execute(
            'INSERT INTO tn_growth_market_discovery_runs
             (organization_id,run_id,universe_id,status,requested_limit,created_by,started_at)
             VALUES(:organization_id,:run_id,:universe_id,\'running\',:requested_limit,:created_by,NOW(6))',
            ['organization_id'=>$organizationId,'run_id'=>$runId,'universe_id'=>$universeId,'requested_limit'=>$requestedLimit,'created_by'=>$actorId],
        );
    }

    public function acquireRunLease(string $organizationId,string $runId,string $leaseToken,int $ttlSeconds):bool
    {
        if(!preg_match('/^[a-f0-9]{32}$/',$leaseToken))throw new InvalidArgumentException('Growth market run lease token is invalid.');
        if($ttlSeconds<60||$ttlSeconds>3600)throw new InvalidArgumentException('Growth market run lease TTL is invalid.');
        $statement=$this->connection->prepare(
            'UPDATE tn_growth_market_discovery_runs
             SET lease_token=:lease_token,
                 lease_expires_at=DATE_ADD(NOW(6),INTERVAL :ttl_seconds SECOND),
                 attempt_count=attempt_count+1
             WHERE organization_id=:organization_id AND run_id=:run_id AND status=\'running\'
               AND (lease_expires_at IS NULL OR lease_expires_at<NOW(6))'
        );
        $statement->bindValue(':lease_token',$leaseToken);
        $statement->bindValue(':ttl_seconds',$ttlSeconds,PDO::PARAM_INT);
        $statement->bindValue(':organization_id',$organizationId);
        $statement->bindValue(':run_id',$runId);
        $statement->execute();
        return $statement->rowCount()===1;
    }

    public function completeRun(
        string $organizationId,string $runId,string $status,int $collectedCount,int $accountCount,
        int $existingCount,int $monitoredCount,int $opportunityCount,?string $nextCursor,?string $errorSummary,string $leaseToken
    ):void {
        if(!in_array($status,['completed','partial','failed'],true))throw new InvalidArgumentException('Growth market run status is invalid.');
        $statement=$this->connection->prepare(
            'UPDATE tn_growth_market_discovery_runs
             SET status=:status,collected_count=:collected_count,account_count=:account_count,existing_count=:existing_count,
                 monitored_count=:monitored_count,opportunity_count=:opportunity_count,next_cursor=:next_cursor,
                 error_summary=:error_summary,finished_at=NOW(6),lease_token=NULL,lease_expires_at=NULL
             WHERE organization_id=:organization_id AND run_id=:run_id AND status=\'running\' AND lease_token=:lease_token'
        );
        $statement->execute([
            'status'=>$status,'collected_count'=>$collectedCount,'account_count'=>$accountCount,'existing_count'=>$existingCount,
            'monitored_count'=>$monitoredCount,'opportunity_count'=>$opportunityCount,'next_cursor'=>$nextCursor,
            'error_summary'=>$errorSummary,'organization_id'=>$organizationId,'run_id'=>$runId,'lease_token'=>$leaseToken,
        ]);
        if($statement->rowCount()!==1)throw new InvalidArgumentException('Growth market run could not be completed.');
    }

    public function viewRun(string $organizationId,string $runId):?array
    {
        return $this->one(
            'SELECT * FROM tn_growth_market_discovery_runs WHERE organization_id=:organization_id AND run_id=:run_id LIMIT 1',
            ['organization_id'=>$organizationId,'run_id'=>$runId],
        );
    }

    public function latestRuns(string $organizationId,string $universeId,int $limit=20):array
    {
        $limit=max(1,min(100,$limit));
        $statement=$this->connection->prepare(
            'SELECT * FROM tn_growth_market_discovery_runs
             WHERE organization_id=:organization_id AND universe_id=:universe_id
             ORDER BY started_at DESC,run_id DESC LIMIT '.$limit
        );
        $statement->execute(['organization_id'=>$organizationId,'universe_id'=>$universeId]);
        return $statement->fetchAll(PDO::FETCH_ASSOC)?:[];
    }

    public function upsertMembership(
        string $organizationId,string $universeId,string $accountId,string $externalKeyHash,
        int $fitScore,string $status,string $sourceReference
    ):void {
        if(!in_array($status,['below_threshold','monitoring','opportunity'],true))throw new InvalidArgumentException('Growth market membership status is invalid.');
        $this->execute(
            'INSERT INTO tn_growth_market_memberships
             (organization_id,universe_id,account_id,external_key_hash,fit_score,status,source_reference,first_seen_at,last_seen_at)
             VALUES(:organization_id,:universe_id,:account_id,:external_key_hash,:fit_score,:status,:source_reference,NOW(6),NOW(6))
             ON DUPLICATE KEY UPDATE external_key_hash=VALUES(external_key_hash),fit_score=VALUES(fit_score),
               status=IF(candidate_id IS NULL,VALUES(status),\'opportunity\'),source_reference=VALUES(source_reference),last_seen_at=NOW(6)',
            [
                'organization_id'=>$organizationId,'universe_id'=>$universeId,'account_id'=>$accountId,
                'external_key_hash'=>$externalKeyHash,'fit_score'=>$fitScore,'status'=>$status,'source_reference'=>$sourceReference,
            ],
        );
    }

    public function membershipsForAccount(string $organizationId,string $accountId):array
    {
        $statement=$this->connection->prepare(
            'SELECT m.*,u.enabled,u.min_icp_fit,u.opportunity_type,u.growth_mode,u.target_domain
             FROM tn_growth_market_memberships m
             INNER JOIN tn_growth_market_universes u
               ON u.organization_id=m.organization_id AND u.universe_id=m.universe_id
             WHERE m.organization_id=:organization_id AND m.account_id=:account_id
             ORDER BY m.universe_id'
        );
        $statement->execute(['organization_id'=>$organizationId,'account_id'=>$accountId]);
        return $statement->fetchAll(PDO::FETCH_ASSOC)?:[];
    }

    public function membershipsForUniverse(string $organizationId,string $universeId,int $limit=200):array
    {
        $limit=max(1,min(500,$limit));
        $statement=$this->connection->prepare(
            'SELECT m.*,a.name account_name,a.canonical_domain
             FROM tn_growth_market_memberships m
             LEFT JOIN tn_growth_accounts a ON a.organization_id=m.organization_id AND a.account_id=m.account_id
             WHERE m.organization_id=:organization_id AND m.universe_id=:universe_id
             ORDER BY m.last_seen_at DESC,m.account_id LIMIT '.$limit
        );
        $statement->execute(['organization_id'=>$organizationId,'universe_id'=>$universeId]);
        return $statement->fetchAll(PDO::FETCH_ASSOC)?:[];
    }

    public function claimOpportunityTrigger(string $organizationId,string $universeId,string $accountId,string $signalId):string
    {
        $statement=$this->connection->prepare(
            'UPDATE tn_growth_market_memberships SET trigger_signal_id=COALESCE(trigger_signal_id,:signal_id),last_seen_at=last_seen_at
             WHERE organization_id=:organization_id AND universe_id=:universe_id AND account_id=:account_id'
        );
        $statement->execute([
            'signal_id'=>$signalId,'organization_id'=>$organizationId,'universe_id'=>$universeId,'account_id'=>$accountId,
        ]);
        $row=$this->one(
            'SELECT trigger_signal_id FROM tn_growth_market_memberships
             WHERE organization_id=:organization_id AND universe_id=:universe_id AND account_id=:account_id LIMIT 1',
            ['organization_id'=>$organizationId,'universe_id'=>$universeId,'account_id'=>$accountId],
        );
        $value=trim((string)($row['trigger_signal_id']??''));
        if($value==='')throw new InvalidArgumentException('Growth market opportunity trigger could not be claimed.');
        return $value;
    }

    public function setMembershipCandidate(string $organizationId,string $universeId,string $accountId,string $candidateId):void
    {
        $statement=$this->connection->prepare(
            'UPDATE tn_growth_market_memberships SET candidate_id=:candidate_id,status=\'opportunity\',last_seen_at=NOW(6)
             WHERE organization_id=:organization_id AND universe_id=:universe_id AND account_id=:account_id'
        );
        $statement->execute([
            'candidate_id'=>$candidateId,'organization_id'=>$organizationId,'universe_id'=>$universeId,'account_id'=>$accountId,
        ]);
        if($statement->rowCount()>1)throw new InvalidArgumentException('Growth market membership candidate update changed too many rows.');
    }

    private function hydrate(array $row):GrowthMarketUniverse
    {
        $type=OpportunityType::tryFrom((string)$row['opportunity_type'])??throw new InvalidArgumentException('Stored market opportunity type is invalid.');
        $mode=GrowthMode::tryFrom((string)$row['growth_mode'])??throw new InvalidArgumentException('Stored market growth mode is invalid.');
        return new GrowthMarketUniverse(
            (string)$row['universe_id'],OrganizationId::fromString((string)$row['organization_id']),(string)$row['name'],
            (string)$row['source_type'],(string)$row['source_url'],(string)$row['auth_mode'],(string)$row['credential_reference'],
            $row['api_key_header']===null?null:(string)$row['api_key_header'],(string)$row['profile_id'],(int)$row['profile_revision'],
            (int)$row['min_icp_fit'],$type,$mode,(string)$row['target_domain'],(bool)$row['enabled'],
        );
    }

    /** @return array<string,mixed>|null */
    private function normalized(?array $row):?array
    {
        if($row===null)return null;
        foreach(['enabled'] as $field)if(array_key_exists($field,$row))$row[$field]=(bool)$row[$field];
        foreach(['profile_revision','min_icp_fit','created_by','updated_by','member_count','opportunity_count'] as $field){
            if(array_key_exists($field,$row)&&$row[$field]!==null)$row[$field]=(int)$row[$field];
        }
        return $row;
    }

    private function execute(string $sql,array $params):void
    {
        $statement=$this->connection->prepare($sql);$statement->execute($params);
    }

    /** @return array<string,mixed>|null */
    private function one(string $sql,array $params):?array
    {
        $statement=$this->connection->prepare($sql);$statement->execute($params);
        $row=$statement->fetch(PDO::FETCH_ASSOC);return $row===false?null:$row;
    }
}
