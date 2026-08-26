<?php
declare(strict_types=1);

namespace Infrastructure\Persistence\MySql\Sales;

use Domains\Sales\Application\Contract\DealRepositoryInterface;
use Domains\Sales\Application\DTO\OperationResult;
use Domains\Sales\Model\DealChangeSet;
use PDO;
use Throwable;

final readonly class MysqlDealRepository implements DealRepositoryInterface
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
}
