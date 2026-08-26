<?php
declare(strict_types=1);

namespace Infrastructure\Persistence\MySql\Sales;

use Domains\Sales\Application\Contract\MessageGatewayInterface;
use Domains\Sales\Application\DTO\OperationResult;
use Domains\Sales\Application\DTO\SendMessageCommand;
use PDO;
use Throwable;

final readonly class MysqlMessageGateway implements MessageGatewayInterface
{
    public function __construct(private PDO $connection)
    {
    }

    public function send(SendMessageCommand $command): OperationResult
    {
        return $this->recordIdempotently('message', $command->idempotencyKey, function () use ($command): string {
            $statement = $this->connection->prepare(
                "INSERT INTO tn_client_case_activities (client_case_id, activity_type, title, body, completed_at) "
                . "VALUES (:id, 'message', :title, :body, NOW())"
            );
            $statement->execute([
                'id' => $command->dealReference,
                'title' => 'Outbound via ' . $command->channel,
                'body' => $command->body,
            ]);
            return (string) $this->connection->lastInsertId();
        }, $command->organizationId, ['channel' => $command->channel]);
    }

    private function recordIdempotently(string $entityType, string $key, callable $operation, string $organizationId, array $data): OperationResult
    {
        $ownsTransaction = !$this->connection->inTransaction();
        try {
            if ($ownsTransaction) $this->connection->beginTransaction();
            $existing = $this->connection->prepare(
                'SELECT external_id FROM cos_external_references WHERE organization_id = :organization_id '
                . "AND provider = 'aida' AND entity_type = :entity_type AND cos_reference = :cos_reference LIMIT 1"
            );
            $existing->execute(['organization_id' => $organizationId, 'entity_type' => $entityType, 'cos_reference' => 'action:' . $key]);
            $externalId = $existing->fetchColumn();
            if ($externalId !== false) {
                if ($ownsTransaction) $this->connection->commit();
                return OperationResult::success((string) $externalId, ['duplicate' => true, ...$data]);
            }

            $externalId = $operation();
            $mapping = $this->connection->prepare(
                "INSERT INTO cos_external_references (organization_id, provider, entity_type, external_id, cos_reference, last_synced_at) "
                . "VALUES (:organization_id, 'aida', :entity_type, :external_id, :cos_reference, NOW(6))"
            );
            $mapping->execute([
                'organization_id' => $organizationId, 'entity_type' => $entityType,
                'external_id' => $externalId, 'cos_reference' => 'action:' . $key,
            ]);
            if ($ownsTransaction) $this->connection->commit();
            return OperationResult::success($externalId, $data);
        } catch (Throwable $exception) {
            if ($ownsTransaction && $this->connection->inTransaction()) $this->connection->rollBack();
            return OperationResult::failure($exception->getMessage(), $data);
        }
    }
}
