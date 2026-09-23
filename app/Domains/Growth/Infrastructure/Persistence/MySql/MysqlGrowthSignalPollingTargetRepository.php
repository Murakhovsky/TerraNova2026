<?php
declare(strict_types=1);

namespace Domains\Growth\Infrastructure\Persistence\MySql;

use Domains\Growth\Application\Contract\GrowthSignalPollingTargetRepositoryInterface;
use InvalidArgumentException;
use PDO;

final readonly class MysqlGrowthSignalPollingTargetRepository implements GrowthSignalPollingTargetRepositoryInterface
{
    public function __construct(private PDO $connection){}

    public function targets(int $organizationLimit=500):array
    {
        if($organizationLimit<1||$organizationLimit>1000){
            throw new InvalidArgumentException('Growth polling organization limit must be between 1 and 1000.');
        }

        $rowLimit=$organizationLimit*2;
        $statement=$this->connection->query(
            'SELECT organization_id,collector
             FROM (
                 SELECT DISTINCT organization_id,\'rss_atom\' AS collector
                 FROM tn_growth_signal_feeds
                 WHERE enabled=1
                 UNION
                 SELECT DISTINCT organization_id,\'credentialed_json\' AS collector
                 FROM tn_growth_json_signal_sources
                 WHERE enabled=1
             ) targets
             ORDER BY organization_id,collector
             LIMIT '.$rowLimit
        );

        $grouped=[];
        foreach($statement->fetchAll(PDO::FETCH_ASSOC)?:[] as $row){
            $organizationId=trim((string)($row['organization_id']??''));
            $collector=trim((string)($row['collector']??''));
            if($organizationId===''||$collector==='')continue;
            if(!isset($grouped[$organizationId])){
                if(count($grouped)>=$organizationLimit)break;
                $grouped[$organizationId]=[];
            }
            $grouped[$organizationId][$collector]=true;
        }

        $result=[];
        foreach($grouped as $organizationId=>$collectors){
            $names=array_keys($collectors);
            sort($names,SORT_STRING);
            $result[]=['organization_id'=>$organizationId,'collectors'=>$names];
        }
        return $result;
    }

    public function targetForOrganization(string $organizationId):array
    {
        $organizationId=trim($organizationId);
        if($organizationId===''||mb_strlen($organizationId)>64){
            throw new InvalidArgumentException('Growth polling organization id is invalid.');
        }

        $statement=$this->connection->prepare(
            'SELECT collector,source_count
             FROM (
                 SELECT \'rss_atom\' AS collector,COUNT(*) AS source_count
                 FROM tn_growth_signal_feeds
                 WHERE organization_id=:organization_id AND enabled=1
                 UNION ALL
                 SELECT \'credentialed_json\' AS collector,COUNT(*) AS source_count
                 FROM tn_growth_json_signal_sources
                 WHERE organization_id=:organization_id2 AND enabled=1
             ) source_counts
             WHERE source_count>0
             ORDER BY collector'
        );
        $statement->execute([
            'organization_id'=>$organizationId,
            'organization_id2'=>$organizationId,
        ]);

        $counts=[];
        foreach($statement->fetchAll(PDO::FETCH_ASSOC)?:[] as $row){
            $collector=trim((string)($row['collector']??''));
            $count=(int)($row['source_count']??0);
            if($collector!==''&&$count>0)$counts[$collector]=$count;
        }
        ksort($counts,SORT_STRING);

        return [
            'organization_id'=>$organizationId,
            'collectors'=>array_keys($counts),
            'source_counts'=>$counts,
        ];
    }
}
