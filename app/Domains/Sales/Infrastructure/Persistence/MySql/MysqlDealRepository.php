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

    public function getForStageChange(string $organizationId, string $dealId): ?array
    {
        $statement = $this->connection->prepare(
            'SELECT id, organization_id, pipeline_id, stage_id, stage, status, closed_at, created_at, '
            . 'deal_value, currency, assigned_user_id '
            . 'FROM tn_client_cases WHERE id = :id AND organization_id = :organization_id LIMIT 1'
        );
        $statement->execute(['id' => $dealId, 'organization_id' => $organizationId]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? $row : null;
    }

    public function changeStage(
        string $organizationId,
        string $dealId,
        string $pipelineId,
        string $expectedStageId,
        string $stageId,
        string $legacyStage,
        float $probability,
        bool $terminal,
        bool $won,
        bool $lost,
        ?string $lostReasonId = null,
        ?string $lostReasonNote = null,
    ): bool {
        $statement = $this->connection->prepare(
            'UPDATE tn_client_cases
             SET stage_id = :stage_id, stage = :stage, probability = :probability,
                 status = :status, closed_at = :closed_at,
                 lost_reason_id = CASE WHEN :set_lost_reason = 1 THEN :lost_reason_id ELSE lost_reason_id END,
                 lost_reason_note = CASE WHEN :set_lost_note = 1 THEN :lost_reason_note ELSE lost_reason_note END,
                 lost_reason = CASE WHEN :set_legacy_lost = 1 THEN COALESCE(NULLIF(:legacy_lost_note, ""), lost_reason) ELSE lost_reason END,
                 updated_at = NOW()
             WHERE id = :id AND organization_id = :organization_id
               AND pipeline_id = :pipeline_id AND stage_id = :expected_stage_id'
        );
        $note = $lostReasonNote !== null ? mb_substr(trim($lostReasonNote), 0, 4000) : null;
        $statement->execute([
            'stage_id' => $stageId,
            'stage' => $legacyStage,
            'probability' => $probability,
            'status' => $won ? 'closed' : ($lost ? 'lost' : 'active'),
            'closed_at' => $terminal ? date('Y-m-d H:i:s') : null,
            'set_lost_reason' => $lost ? 1 : 0,
            'lost_reason_id' => $lost ? $lostReasonId : null,
            'set_lost_note' => $lost ? 1 : 0,
            'lost_reason_note' => $lost ? $note : null,
            'set_legacy_lost' => $lost ? 1 : 0,
            'legacy_lost_note' => $lost ? $note : null,
            'id' => $dealId,
            'organization_id' => $organizationId,
            'pipeline_id' => $pipelineId,
            'expected_stage_id' => $expectedStageId,
        ]);

        // Stage history is event-owned from V0.8.1 onward. Direct writes here used the
        // retired V0.6.7 schema and could race the durable DealStageChanged projection.
        return $statement->rowCount() === 1;
    }

    public function assignOwner(string $organizationId, string $dealId, int $ownerId): OperationResult
    {
        try {
            $deal = $this->connection->prepare(
                'SELECT assigned_user_id FROM tn_client_cases '
                . 'WHERE id=:id AND organization_id=:organization_id LIMIT 1 FOR UPDATE'
            );
            $deal->execute(['id' => $dealId, 'organization_id' => $organizationId]);
            $current = $deal->fetchColumn();
            if ($current === false) {
                return OperationResult::failure('Deal was not found in the current organization.');
            }

            $previousOwnerId = $current !== null ? (int) $current : null;
            if ($previousOwnerId === $ownerId) {
                return OperationResult::success($dealId, [
                    'changed' => false,
                    'previous_owner_id' => $previousOwnerId,
                    'owner_id' => $ownerId,
                ]);
            }

            $owner = $this->connection->prepare(
                'SELECT id FROM tn_users WHERE id = :owner_id '
                . 'AND organization_id = :organization_id AND status = "active" LIMIT 1'
            );
            $owner->execute(['owner_id' => $ownerId, 'organization_id' => $organizationId]);
            if ($owner->fetchColumn() === false) {
                return OperationResult::failure('Owner was not found or is inactive in the current organization.');
            }

            $statement = $this->connection->prepare(
                'UPDATE tn_client_cases SET assigned_user_id=:owner_id,updated_at=NOW() '
                . 'WHERE id=:id AND organization_id=:organization_id'
            );
            $statement->execute([
                'owner_id' => $ownerId,
                'id' => $dealId,
                'organization_id' => $organizationId,
            ]);
            if ($statement->rowCount() !== 1) {
                return OperationResult::failure('Deal owner could not be changed.');
            }

            return OperationResult::success($dealId, [
                'changed' => true,
                'previous_owner_id' => $previousOwnerId,
                'owner_id' => $ownerId,
            ]);
        } catch (Throwable $exception) {
            return OperationResult::failure($exception->getMessage());
        }
    }
}
