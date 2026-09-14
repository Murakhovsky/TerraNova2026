<?php
declare(strict_types=1);

namespace Domains\Sales\Infrastructure\Persistence\MySql;

use Domains\Sales\Application\Contract\MessageGatewayInterface;
use Domains\Sales\Application\DTO\OperationResult;
use Domains\Sales\Application\DTO\SendMessageCommand;
use PDO;
use Infrastructure\Platform\Persistence\ExternalReferenceStoreInterface;
use Throwable;

final readonly class MysqlMessageGateway implements MessageGatewayInterface
{
    public function __construct(private PDO $connection, private ExternalReferenceStoreInterface $references)
    {
    }

    public function send(SendMessageCommand $command): OperationResult
    {
        return $this->recordIdempotently('message', $command->idempotencyKey, function () use ($command): string {
            $statement = $this->connection->prepare(
                "INSERT INTO tn_client_case_activities (organization_id, client_case_id, activity_type, title, body, completed_at) "
                . "SELECT :organization_id, id, 'message', :title, :body, NOW() FROM tn_client_cases "
                . 'WHERE id = :id AND organization_id = :organization_scope'
            );
            $statement->execute([
                'id' => $command->dealReference,
                'title' => 'Outbound via ' . $command->channel,
                'body' => $command->body,
                'organization_id' => $command->organizationId,
                'organization_scope' => $command->organizationId,
            ]);
            if ($statement->rowCount() !== 1) {
                throw new \RuntimeException('Deal was not found in the current organization.');
            }
            return (string) $this->connection->lastInsertId();
        }, $command->organizationId, ['channel' => $command->channel]);
    }

    private function recordIdempotently(string $entityType, string $key, callable $operation, string $organizationId, array $data): OperationResult
    {
        $ownsTransaction = !$this->connection->inTransaction();
        try {
            if ($ownsTransaction) $this->connection->beginTransaction();
            $reference = 'action:' . $key;
            $externalId = $this->references->find($organizationId, 'aida', $entityType, $reference);
            if ($externalId !== null) {
                if ($ownsTransaction) $this->connection->commit();
                return OperationResult::success((string) $externalId, ['duplicate' => true, ...$data]);
            }

            $externalId = $operation();
            $this->references->put($organizationId, 'aida', $entityType, $externalId, $reference);
            if ($ownsTransaction) $this->connection->commit();
            return OperationResult::success($externalId, $data);
        } catch (Throwable $exception) {
            if ($ownsTransaction && $this->connection->inTransaction()) $this->connection->rollBack();
            return OperationResult::failure($exception->getMessage(), $data);
        }
    }
}
