<?php
declare(strict_types=1);

namespace Domains\Growth\Infrastructure\Persistence\MySql;

use Domains\Growth\Application\Contract\GrowthEngagementLimitProfileRepositoryInterface;
use InvalidArgumentException;
use PDO;

final readonly class MysqlGrowthEngagementLimitProfileRepository implements GrowthEngagementLimitProfileRepositoryInterface
{
    public function __construct(private PDO $connection) {}

    public function latest(string $organizationId):?array
    {
        $statement=$this->connection->prepare(
            'SELECT organization_id,profile_id,revision,daily_limit,contact_cooldown_hours,reason,created_by,created_at '
            .'FROM tn_growth_engagement_limit_profiles WHERE organization_id=:organization_id '
            .'ORDER BY revision DESC LIMIT 1'
        );
        $statement->execute(['organization_id'=>$organizationId]);
        $row=$statement->fetch(PDO::FETCH_ASSOC);
        if($row===false)return null;
        $row['revision']=(int)$row['revision'];
        $row['daily_limit']=(int)$row['daily_limit'];
        $row['contact_cooldown_hours']=(int)$row['contact_cooldown_hours'];
        $row['created_by']=(int)$row['created_by'];
        return $row;
    }

    public function append(array $profile):void
    {
        $statement=$this->connection->prepare(
            'INSERT INTO tn_growth_engagement_limit_profiles '
            .'(organization_id,profile_id,revision,daily_limit,contact_cooldown_hours,reason,created_by,created_at) '
            .'VALUES(:organization_id,:profile_id,:revision,:daily_limit,:contact_cooldown_hours,:reason,:created_by,:created_at)'
        );
        $statement->execute([
            'organization_id'=>$profile['organization_id'],
            'profile_id'=>$profile['profile_id'],
            'revision'=>$profile['revision'],
            'daily_limit'=>$profile['daily_limit'],
            'contact_cooldown_hours'=>$profile['contact_cooldown_hours'],
            'reason'=>$profile['reason'],
            'created_by'=>$profile['created_by'],
            'created_at'=>(new \DateTimeImmutable((string)$profile['created_at']))->format('Y-m-d H:i:s.u'),
        ]);

        $stored=$this->latest((string)$profile['organization_id'])
            ?? throw new InvalidArgumentException('Growth engagement limit profile could not be read back.');
        if((string)$stored['profile_id']!==(string)$profile['profile_id']){
            throw new InvalidArgumentException('Growth engagement limit profile revision conflict.');
        }
    }
}
