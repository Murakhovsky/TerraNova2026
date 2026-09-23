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
}
