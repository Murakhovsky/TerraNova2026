<?php
declare(strict_types=1);

namespace Domains\Growth\Infrastructure\Persistence\MySql;

use Domains\Growth\Application\Contract\GrowthEngagementActivationProfileRepositoryInterface;
use InvalidArgumentException;
use PDO;

final readonly class MysqlGrowthEngagementActivationProfileRepository implements GrowthEngagementActivationProfileRepositoryInterface
{
    public function __construct(private PDO $connection) {}

    public function latest(string $organizationId):?array
    {
        $statement=$this->connection->prepare(
            'SELECT organization_id,profile_id,revision,email_mode,linkedin_mode,phone_mode,reason,created_by,created_at
             FROM tn_growth_engagement_activation_profiles
             WHERE organization_id=:organization_id
             ORDER BY revision DESC LIMIT 1'
        );
        $statement->execute(['organization_id'=>$organizationId]);
        $row=$statement->fetch(PDO::FETCH_ASSOC);
        if($row===false)return null;
        $row['revision']=(int)$row['revision'];
        $row['created_by']=(int)$row['created_by'];
        return $row;
    }

    public function append(array $profile):void
    {
        $statement=$this->connection->prepare(
            'INSERT INTO tn_growth_engagement_activation_profiles
             (organization_id,profile_id,revision,email_mode,linkedin_mode,phone_mode,reason,created_by,created_at)
             VALUES(:organization_id,:profile_id,:revision,:email_mode,:linkedin_mode,:phone_mode,:reason,:created_by,:created_at)'
        );
        $statement->execute([
            'organization_id'=>$profile['organization_id'],'profile_id'=>$profile['profile_id'],'revision'=>$profile['revision'],
            'email_mode'=>$profile['email_mode'],'linkedin_mode'=>$profile['linkedin_mode'],'phone_mode'=>$profile['phone_mode'],
            'reason'=>$profile['reason'],'created_by'=>$profile['created_by'],'created_at'=>$profile['created_at'],
        ]);
        $stored=$this->latest((string)$profile['organization_id'])
            ?? throw new InvalidArgumentException('Growth engagement activation profile could not be read back.');
        if((string)($stored['profile_id']??'')!==(string)$profile['profile_id']){
            throw new InvalidArgumentException('Growth engagement activation profile revision conflict.');
        }
    }
}
