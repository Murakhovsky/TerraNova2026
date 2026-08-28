<?php
declare(strict_types=1);

namespace Domains\Sales\Infrastructure\Persistence\MySql;

use Domains\Sales\Application\Contract\FollowupRepositoryInterface;
use Domains\Sales\Application\DTO\OperationResult;
use Domains\Sales\Application\DTO\ScheduleFollowupCommand;
use PDO;
use Infrastructure\Platform\Persistence\ExternalReferenceStoreInterface;
use Throwable;

final readonly class MysqlFollowupRepository implements FollowupRepositoryInterface
{
    public function __construct(private PDO $connection, private ExternalReferenceStoreInterface $references)
    {
    }

    public function schedule(ScheduleFollowupCommand $command): OperationResult
    {
        $ownsTransaction = !$this->connection->inTransaction();
        try {
            if ($ownsTransaction) $this->connection->beginTransaction();
            $reference = 'action:' . $command->idempotencyKey;
            $externalId = $this->references->find($command->organizationId, 'aida', 'followup', $reference);
            if ($externalId !== null) {
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
            $this->references->put($command->organizationId, 'aida', 'followup', $externalId, $reference);
            if ($ownsTransaction) $this->connection->commit();
            return OperationResult::success($externalId);
        } catch (Throwable $exception) {
            if ($ownsTransaction && $this->connection->inTransaction()) $this->connection->rollBack();
            return OperationResult::failure($exception->getMessage());
        }
    }
}
