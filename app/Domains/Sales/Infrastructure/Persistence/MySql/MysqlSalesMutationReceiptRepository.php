<?php
declare(strict_types=1);

namespace Domains\Sales\Infrastructure\Persistence\MySql;

use Domains\Sales\Application\Contract\SalesMutationReceiptRepositoryInterface;
use PDO;

final readonly class MysqlSalesMutationReceiptRepository implements SalesMutationReceiptRepositoryInterface
{
    public function __construct(private PDO $connection)
    {
    }

    public function find(string $organizationId, string $operationType, string $idempotencyKey): ?string
    {
        $statement = $this->connection->prepare(
            'SELECT mutation_id FROM sales_operation_receipts '
            . 'WHERE organization_id=:organization_id AND operation_type=:operation_type AND idempotency_key=:idempotency_key LIMIT 1'
        );
        $statement->execute([
            'organization_id' => $organizationId,
            'operation_type' => $operationType,
            'idempotency_key' => $idempotencyKey,
        ]);
        $value = $statement->fetchColumn();
        return $value === false ? null : (string) $value;
    }

    public function claim(string $organizationId, string $operationType, string $idempotencyKey, string $pendingMutationId): bool
    {
        $statement = $this->connection->prepare(
            'INSERT IGNORE INTO sales_operation_receipts(organization_id,operation_type,idempotency_key,mutation_id) '
            . 'VALUES(:organization_id,:operation_type,:idempotency_key,:mutation_id)'
        );
        $statement->execute([
            'organization_id' => $organizationId,
            'operation_type' => $operationType,
            'idempotency_key' => $idempotencyKey,
            'mutation_id' => $pendingMutationId,
        ]);
        return $statement->rowCount() === 1;
    }

    public function complete(
        string $organizationId,
        string $operationType,
        string $idempotencyKey,
        string $pendingMutationId,
        string $mutationId,
    ): bool {
        $statement = $this->connection->prepare(
            'UPDATE sales_operation_receipts SET mutation_id=:mutation_id '
            . 'WHERE organization_id=:organization_id AND operation_type=:operation_type '
            . 'AND idempotency_key=:idempotency_key AND mutation_id=:pending_mutation_id'
        );
        $statement->execute([
            'organization_id' => $organizationId,
            'operation_type' => $operationType,
            'idempotency_key' => $idempotencyKey,
            'pending_mutation_id' => $pendingMutationId,
            'mutation_id' => $mutationId,
        ]);
        return $statement->rowCount() === 1;
    }
}
