<?php
declare(strict_types=1);

namespace Domains\Sales\Infrastructure\Persistence\MySql;

use Domains\Sales\Application\Contract\DealRepositoryInterface;
use Domains\Sales\Application\Contract\DealStageRepositoryInterface;
use Domains\Sales\Application\Contract\DealAssignmentRepositoryInterface;
use Domains\Sales\Application\DTO\OperationResult;
use Domains\Sales\Model\DealChangeSet;
use PDO;
use Throwable;

final readonly class MysqlDealRepository implements DealRepositoryInterface, DealStageRepositoryInterface, DealAssignmentRepositoryInterface
{
    public function __construct(private PDO $connection)
    {
    }

    public function update(string $organizationId, string $dealReference, DealChangeSet $changes): OperationResult
    {
        try {
            $exists = $this->connection->prepare(
                'SELECT 1 FROM tn_client_cases WHERE id = :id AND organization_id = :organization_id LIMIT 1'
            );
            $exists->execute(['id' => $dealReference, 'organization_id' => $organizationId]);
            if ($exists->fetchColumn() === false) {
                return OperationResult::failure('Deal was not found in the current organization.');
            }
            $values = $changes->toArray();
            $sets = [];
            $parameters = ['id' => $dealReference, 'organization_id' => $organizationId];
            foreach ($values as $field => $value) {
                $sets[] = $field . ' = :' . $field;
                $parameters[$field] = $value;
            }
            $statement = $this->connection->prepare(
                'UPDATE tn_client_cases SET ' . implode(', ', $sets)
                . ' WHERE id = :id AND organization_id = :organization_id'
            );
            $statement->execute($parameters);

            return OperationResult::success($dealReference, [
                'changes' => $values,
                'rows_affected' => $statement->rowCount(),
            ]);
        } catch (Throwable $exception) {
            return OperationResult::failure($exception->getMessage());
        }
    }

    public function getForStageChange(string $organizationId,string $dealId):?array
    {
        $statement=$this->connection->prepare('SELECT id,organization_id,pipeline_id,stage_id,stage,status,closed_at,created_at FROM tn_client_cases WHERE id=:id AND organization_id=:organization_id LIMIT 1');
        $statement->execute(['id'=>$dealId,'organization_id'=>$organizationId]);$row=$statement->fetch(PDO::FETCH_ASSOC);return is_array($row)?$row:null;
    }

    public function changeStage(string $organizationId,string $dealId,string $pipelineId,string $expectedStageId,string $stageId,string $legacyStage,float $probability,bool $terminal,bool $won,bool $lost):bool
    {
        $statement=$this->connection->prepare('UPDATE tn_client_cases SET stage_id=:stage_id,stage=:stage,probability=:probability,status=:status,closed_at=:closed_at,won_reason=CASE WHEN :won=0 THEN won_reason ELSE won_reason END,lost_reason=CASE WHEN :lost=0 THEN lost_reason ELSE lost_reason END,updated_at=NOW() WHERE id=:id AND organization_id=:organization_id AND pipeline_id=:pipeline_id AND stage_id=:expected_stage_id');
        $statement->execute(['expected_stage_id'=>$expectedStageId,'stage_id'=>$stageId,'stage'=>$legacyStage,'probability'=>$probability,'status'=>$won?'closed':($lost?'lost':'active'),'closed_at'=>$terminal?date('Y-m-d H:i:s'):null,'won'=>$won?1:0,'lost'=>$lost?1:0,'id'=>$dealId,'organization_id'=>$organizationId,'pipeline_id'=>$pipelineId]);
        if ($statement->rowCount() !== 1) return false;

        // ChangeDealStage runs this repository call inside the canonical Sales transaction.
        // Close the exact current projection. Legacy rows seeded by the migration stay marked as backfill.
        $close=$this->connection->prepare('UPDATE sales_deal_stage_history SET left_at=NOW() WHERE organization_id=:organization_id AND deal_id=:deal_id AND pipeline_id=:pipeline_id AND stage_id=:stage_id AND left_at IS NULL');
        $close->execute(['organization_id'=>$organizationId,'deal_id'=>$dealId,'pipeline_id'=>$pipelineId,'stage_id'=>$expectedStageId]);

        // A Deal created after the migration has no row until its first transition. In that case
        // created_at is the exact entry time for its initial stage, so persist the closed initial visit.
        if ($close->rowCount() === 0) {
            $seed=$this->connection->prepare('INSERT INTO sales_deal_stage_history (organization_id,deal_id,pipeline_id,stage_id,entered_at,left_at,is_backfill) SELECT organization_id,id,pipeline_id,:stage_id,created_at,NOW(),0 FROM tn_client_cases WHERE id=:deal_id AND organization_id=:organization_id AND pipeline_id=:pipeline_id');
            $seed->execute(['stage_id'=>$expectedStageId,'deal_id'=>$dealId,'organization_id'=>$organizationId,'pipeline_id'=>$pipelineId]);
        }

        $open=$this->connection->prepare('INSERT INTO sales_deal_stage_history (organization_id,deal_id,pipeline_id,stage_id,entered_at,left_at,is_backfill) VALUES (:organization_id,:deal_id,:pipeline_id,:stage_id,NOW(),NULL,0)');
        $open->execute(['organization_id'=>$organizationId,'deal_id'=>$dealId,'pipeline_id'=>$pipelineId,'stage_id'=>$stageId]);
        return true;
    }

    public function assignOwner(string $organizationId, string $dealId, int $ownerId): OperationResult
    {
        try {
            $owner = $this->connection->prepare('SELECT id FROM tn_users WHERE id = :owner_id AND organization_id = :organization_id AND status = "active" LIMIT 1');
            $owner->execute(['owner_id' => $ownerId, 'organization_id' => $organizationId]);
            if ($owner->fetchColumn() === false) return OperationResult::failure('Owner was not found or is inactive in the current organization.');
            $statement = $this->connection->prepare('UPDATE tn_client_cases SET assigned_user_id = :owner_id, updated_at = NOW() WHERE id = :id AND organization_id = :organization_id AND (assigned_user_id IS NULL OR assigned_user_id <> :owner_check)');
            $statement->execute(['owner_id' => $ownerId, 'owner_check' => $ownerId, 'id' => $dealId, 'organization_id' => $organizationId]);
            if ($statement->rowCount() === 1) return OperationResult::success($dealId, ['changed' => true]);
            $exists = $this->connection->prepare('SELECT assigned_user_id FROM tn_client_cases WHERE id = :id AND organization_id = :organization_id LIMIT 1');
            $exists->execute(['id' => $dealId, 'organization_id' => $organizationId]);
            return $exists->fetchColumn() === false
                ? OperationResult::failure('Deal was not found in the current organization.')
                : OperationResult::success($dealId, ['changed' => false]);
        } catch (Throwable $exception) {
            return OperationResult::failure($exception->getMessage());
        }
    }
}
