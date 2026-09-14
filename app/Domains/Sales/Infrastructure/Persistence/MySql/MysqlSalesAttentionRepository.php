<?php
declare(strict_types=1);

namespace Domains\Sales\Infrastructure\Persistence\MySql;

use Domains\Sales\Application\Contract\SalesAttentionRepositoryInterface;
use PDO;

final readonly class MysqlSalesAttentionRepository implements SalesAttentionRepositoryInterface
{
    public function __construct(private PDO $connection) {}
    public function inactiveDeals(string $organizationId,\DateTimeImmutable $cutoff,int $limit):array{$s=$this->connection->prepare('SELECT id,last_activity_at FROM tn_client_cases WHERE organization_id=:org AND status="active" AND COALESCE(last_activity_at,created_at)<=:cutoff ORDER BY COALESCE(last_activity_at,created_at) LIMIT '.max(1,min(500,$limit)));$s->execute(['org'=>$organizationId,'cutoff'=>$cutoff->format('Y-m-d H:i:s')]);return $s->fetchAll(PDO::FETCH_ASSOC);}
    public function missedFollowups(string $organizationId,\DateTimeImmutable $now,int $limit):array{$s=$this->connection->prepare('SELECT id,client_case_id,due_at FROM tn_client_case_activities WHERE organization_id=:org AND activity_type="followup" AND completed_at IS NULL AND due_at<:now ORDER BY due_at LIMIT '.max(1,min(500,$limit)));$s->execute(['org'=>$organizationId,'now'=>$now->format('Y-m-d H:i:s')]);return $s->fetchAll(PDO::FETCH_ASSOC);}
    public function claimSignal(string $organizationId,string $type,string $windowKey,string $mutationId):bool{$s=$this->connection->prepare('INSERT IGNORE INTO sales_operation_receipts(organization_id,operation_type,idempotency_key,mutation_id) VALUES(:org,:type,:window_key,:mutation_id)');$s->execute(['org'=>$organizationId,'type'=>$type,'window_key'=>$windowKey,'mutation_id'=>$mutationId]);return $s->rowCount()===1;}
}
