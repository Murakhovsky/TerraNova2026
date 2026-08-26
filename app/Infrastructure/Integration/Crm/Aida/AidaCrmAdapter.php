<?php
declare(strict_types=1);

namespace Infrastructure\Integration\Crm\Aida;

use Domains\Sales\Application\Contract\CrmProviderInterface;
use Domains\Sales\Application\DTO\CreateTaskCommand;
use Domains\Sales\Application\DTO\OperationResult;
use PDO;
use Throwable;

final readonly class AidaCrmAdapter implements CrmProviderInterface
{
    public function __construct(private PDO $connection)
    {
    }

    public function provider(): string
    {
        return 'aida';
    }

    public function createTask(CreateTaskCommand $command): OperationResult
    {
        $ownsTransaction = !$this->connection->inTransaction();
        try {
            if ($ownsTransaction) $this->connection->beginTransaction();
            $reference = 'action:' . $command->idempotencyKey;
            $existing = $this->connection->prepare(
                "SELECT external_id FROM cos_external_references WHERE organization_id = :organization_id AND provider = 'aida' "
                . "AND entity_type = 'task' AND cos_reference = :cos_reference LIMIT 1"
            );
            $existing->execute(['organization_id' => $command->organizationId, 'cos_reference' => $reference]);
            $externalId = $existing->fetchColumn();
            if ($externalId !== false) {
                if ($ownsTransaction) $this->connection->commit();
                return OperationResult::success((string) $externalId, ['duplicate' => true]);
            }

            $statement = $this->connection->prepare(
                "INSERT INTO tn_client_case_activities (client_case_id, activity_type, title, body, due_at) "
                . "VALUES (:client_case_id, 'task', :title, :body, :due_at)"
            );
            $statement->execute([
                'client_case_id' => $command->dealReference, 'title' => $command->title,
                'body' => $command->body, 'due_at' => $command->dueAt?->format('Y-m-d H:i:s'),
            ]);
            $externalId = (string) $this->connection->lastInsertId();
            $mapping = $this->connection->prepare(
                "INSERT INTO cos_external_references (organization_id, provider, entity_type, external_id, cos_reference, last_synced_at) "
                . "VALUES (:organization_id, 'aida', 'task', :external_id, :cos_reference, NOW(6))"
            );
            $mapping->execute(['organization_id' => $command->organizationId, 'external_id' => $externalId, 'cos_reference' => $reference]);
            if ($ownsTransaction) $this->connection->commit();
            return OperationResult::success($externalId, ['provider' => $this->provider()]);
        } catch (Throwable $exception) {
            if ($ownsTransaction && $this->connection->inTransaction()) $this->connection->rollBack();
            return OperationResult::failure($exception->getMessage(), ['provider' => $this->provider()]);
        }
    }
}
