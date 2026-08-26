<?php
declare(strict_types=1);

namespace Infrastructure\Persistence\MySql\Sales;

use Domains\Sales\Application\Contract\FollowupRepositoryInterface;
use Domains\Sales\Application\DTO\OperationResult;
use Domains\Sales\Application\DTO\ScheduleFollowupCommand;
use PDO;
use Throwable;

final readonly class MysqlFollowupRepository implements FollowupRepositoryInterface
{
    public function __construct(private PDO $connection)
    {
    }

    public function schedule(ScheduleFollowupCommand $command): OperationResult
    {
        $ownsTransaction = !$this->connection->inTransaction();
        try {
            if ($ownsTransaction) $this->connection->beginTransaction();
            $reference = 'action:' . $command->idempotencyKey;
            $existing = $this->connection->prepare(
                "SELECT external_id FROM cos_external_references WHERE organization_id = :organization_id AND provider = 'aida' "
                . "AND entity_type = 'followup' AND cos_reference = :cos_reference LIMIT 1"
            );
            $existing->execute(['organization_id' => $command->organizationId, 'cos_reference' => $reference]);
            $externalId = $existing->fetchColumn();
            if ($externalId !== false) {
                if ($ownsTransaction) $this->connection->commit();
                return OperationResult::success((string) $externalId, ['duplicate' => true]);
            }

            $dueAt = $command->dueAt->format('Y-m-d H:i:s');
            $this->connection->prepare(
                'UPDATE tn_client_cases SET next_contact_at = :due_at '
                . 'WHERE id = :id AND organization_id = :organization_id'
            )->execute([
                'id' => $command->dealReference,
                'due_at' => $dueAt,
                'organization_id' => $command->organizationId,
            ]);
            $statement = $this->connection->prepare(
                "INSERT INTO tn_client_case_activities (organization_id, client_case_id, activity_type, title, body, due_at) "
                . "SELECT :organization_id, id, 'task', :title, :body, :due_at FROM tn_client_cases "
                . 'WHERE id = :id AND organization_id = :organization_scope'
            );
            $statement->execute([
                'id' => $command->dealReference, 'title' => $command->title,
                'body' => $command->body, 'due_at' => $dueAt,
                'organization_id' => $command->organizationId,
                'organization_scope' => $command->organizationId,
            ]);
            if ($statement->rowCount() !== 1) {
                throw new \RuntimeException('Deal was not found in the current organization.');
            }
            $externalId = (string) $this->connection->lastInsertId();
            $mapping = $this->connection->prepare(
                "INSERT INTO cos_external_references (organization_id, provider, entity_type, external_id, cos_reference, last_synced_at) "
                . "VALUES (:organization_id, 'aida', 'followup', :external_id, :cos_reference, NOW(6))"
            );
            $mapping->execute(['organization_id' => $command->organizationId, 'external_id' => $externalId, 'cos_reference' => $reference]);
            if ($ownsTransaction) $this->connection->commit();
            return OperationResult::success($externalId);
        } catch (Throwable $exception) {
            if ($ownsTransaction && $this->connection->inTransaction()) $this->connection->rollBack();
            return OperationResult::failure($exception->getMessage());
        }
    }
}
