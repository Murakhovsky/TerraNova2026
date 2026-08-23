<?php
declare(strict_types=1);

namespace Infrastructure\Database\Transaction;

use PDO;
use Throwable;

final class TransactionManager
{
    /** @var list<callable(): void> */
    private array $afterCommit = [];

    public function __construct(private PDO $connection)
    {
    }

    public function transactional(callable $operation): mixed
    {
        if ($this->connection->inTransaction()) {
            return $operation();
        }

        $this->connection->beginTransaction();
        try {
            $result = $operation();
            $this->connection->commit();

            $callbacks = $this->afterCommit;
            $this->afterCommit = [];
            foreach ($callbacks as $callback) {
                $callback();
            }

            return $result;
        } catch (Throwable $exception) {
            if ($this->connection->inTransaction()) {
                $this->connection->rollBack();
            }
            $this->afterCommit = [];
            throw $exception;
        }
    }

    public function isActive(): bool
    {
        return $this->connection->inTransaction();
    }

    public function afterCommit(callable $callback): void
    {
        if (!$this->connection->inTransaction()) {
            $callback();
            return;
        }

        $this->afterCommit[] = $callback;
    }
}
