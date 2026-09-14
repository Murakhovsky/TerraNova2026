<?php
declare(strict_types=1);

namespace Infrastructure\Integration\Crm\Aida;

use Domains\Sales\Application\Contract\CrmProviderInterface;
use Domains\Sales\Application\DTO\CreateTaskCommand;
use Domains\Sales\Application\DTO\OperationResult;
use Domains\Sales\Application\DTO\SendMessageCommand;
use Domains\Sales\Application\DTO\ScheduleFollowupCommand;
use Domains\Sales\Model\DealChangeSet;
use Domains\Sales\Infrastructure\Persistence\MySql\MysqlDealRepository;
use Domains\Sales\Infrastructure\Persistence\MySql\MysqlFollowupRepository;
use Domains\Sales\Infrastructure\Persistence\MySql\MysqlMessageGateway;
use PDO;
use Infrastructure\Platform\Persistence\ExternalReferenceStoreInterface;
use Throwable;

final readonly class AidaCrmAdapter implements CrmProviderInterface
{
    public function __construct(private PDO $connection, private ExternalReferenceStoreInterface $references)
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
            $externalId = $this->references->find($command->organizationId, 'aida', 'task', $reference);
            if ($externalId !== null) {
                if ($ownsTransaction) $this->connection->commit();
                return OperationResult::success((string) $externalId, ['duplicate' => true]);
            }

            $statement = $this->connection->prepare(
                "INSERT INTO tn_client_case_activities (organization_id, client_case_id, activity_type, title, body, due_at) "
                . "SELECT :organization_id, id, 'task', :title, :body, :due_at FROM tn_client_cases "
                . 'WHERE id = :client_case_id AND organization_id = :organization_scope'
            );
            $statement->execute([
                'client_case_id' => $command->dealReference, 'title' => $command->title,
                'body' => $command->body, 'due_at' => $command->dueAt?->format('Y-m-d H:i:s'),
                'organization_id' => $command->organizationId,
                'organization_scope' => $command->organizationId,
            ]);
            if ($statement->rowCount() !== 1) {
                throw new \RuntimeException('Deal was not found in the current organization.');
            }
            $externalId = (string) $this->connection->lastInsertId();
            $this->references->put($command->organizationId, 'aida', 'task', $externalId, $reference);
            if ($ownsTransaction) $this->connection->commit();
            return OperationResult::success($externalId, ['provider' => $this->provider()]);
        } catch (Throwable $exception) {
            if ($ownsTransaction && $this->connection->inTransaction()) $this->connection->rollBack();
            return OperationResult::failure($exception->getMessage(), ['provider' => $this->provider()]);
        }
    }

    public function send(SendMessageCommand $command): OperationResult
    {
        return (new MysqlMessageGateway($this->connection, $this->references))->send($command);
    }

    public function schedule(ScheduleFollowupCommand $command): OperationResult
    {
        return (new MysqlFollowupRepository($this->connection, $this->references))->schedule($command);
    }

    public function update(string $organizationId, string $dealReference, DealChangeSet $changes): OperationResult
    {
        return (new MysqlDealRepository($this->connection))->update($organizationId, $dealReference, $changes);
    }
}
