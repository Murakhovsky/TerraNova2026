<?php
declare(strict_types=1);
namespace Domains\Sales\Infrastructure\ReadModel\MySql;

use Domains\Sales\Application\Contract\SalesActivityEvidenceReadPort;
use PDO;

final readonly class MysqlSalesActivityEvidenceReadModel implements SalesActivityEvidenceReadPort
{
    public function __construct(private PDO $connection) {}
    public function activity(string $org,int $deal,int $id):?array
    {
        return $this->one('SELECT id,organization_id,client_case_id,activity_type,title,body,due_at,completed_at FROM tn_client_case_activities WHERE organization_id=? AND client_case_id=? AND id=?',[$org,$deal,$id]);
    }
    public function propertyMatch(string $org,int $deal,int $property):?array
    {
        return $this->one('SELECT id,organization_id,client_case_id,property_id,match_status,score,note FROM tn_client_case_property_matches WHERE organization_id=? AND client_case_id=? AND property_id=?',[$org,$deal,$property]);
    }
    private function one(string $sql,array $parameters):?array
    {
        $q=$this->connection->prepare($sql.($this->connection->inTransaction()?' FOR UPDATE':''));
        $q->execute($parameters);$row=$q->fetch(PDO::FETCH_ASSOC);
        return is_array($row)?$row:null;
    }
}
