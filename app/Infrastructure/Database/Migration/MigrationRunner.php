<?php
declare(strict_types=1);

namespace Infrastructure\Database\Migration;

use Kernel\Database\MigrationRunnerInterface;
use PDO;
use RuntimeException;
use Throwable;

final readonly class MigrationRunner implements MigrationRunnerInterface
{
    public function __construct(
        private PDO $connection,
        private string $directory,
        private SqlStatementSplitter $splitter,
    ) {
    }

    /** @return array{applied: list<string>, skipped: list<string>} */
    public function migrate(): array
    {
        $this->connection->exec(
            'CREATE TABLE IF NOT EXISTS tn_migrations ('
            . 'id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY, migration VARCHAR(191) NOT NULL, '
            . 'applied_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP, UNIQUE KEY uq_tn_migrations_migration (migration)) '
            . 'ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );
        $lockStatement = $this->connection->query("SELECT GET_LOCK('cos_schema_migrations', 30)");
        $lockRows = $lockStatement->fetchAll(PDO::FETCH_NUM);
        $lockAcquired = (int) ($lockRows[0][0] ?? 0) === 1;
        $lockStatement->closeCursor();
        if (!$lockAcquired) {
            throw new RuntimeException('Could not acquire the migration lock.');
        }
        try {
            $files = glob(rtrim($this->directory, '/\\') . DIRECTORY_SEPARATOR . '*.sql') ?: [];
            sort($files, SORT_STRING);
            $result = ['applied' => [], 'skipped' => []];
            foreach ($files as $file) {
                $name = pathinfo($file, PATHINFO_FILENAME);
                if ($this->isApplied($name)) {
                    $result['skipped'][] = $name;
                    continue;
                }
                $sql = file_get_contents($file);
                if (!is_string($sql)) throw new RuntimeException('Cannot read migration: ' . $file);
                foreach ($this->splitter->split($sql) as $index => $statement) {
                    try {
                        $query = $this->connection->prepare($statement);
                        $query->execute();
                        do {
                            if ($query->columnCount() > 0) {
                                $query->fetchAll(PDO::FETCH_NUM);
                            }
                        } while ($query->nextRowset());
                        $query->closeCursor();
                    } catch (Throwable $exception) {
                        throw new RuntimeException(sprintf(
                            'Migration %s failed at statement %d: %s',
                            $name,
                            $index + 1,
                            $exception->getMessage(),
                        ), 0, $exception);
                    }
                }
                if (!$this->isApplied($name)) {
                    $insert = $this->connection->prepare('INSERT INTO tn_migrations (migration) VALUES (:migration)');
                    $insert->execute(['migration' => $name]);
                }
                $result['applied'][] = $name;
            }
            return $result;
        } finally {
            try {
                $this->connection->exec("DO RELEASE_LOCK('cos_schema_migrations')");
            } catch (Throwable) {
                // Do not mask the migration failure; MySQL releases named locks when this dedicated connection closes.
            }
        }
    }

    /** @return list<array{migration: string, applied_at: string}> */
    public function status(): array
    {
        $statement = $this->connection->query('SELECT migration, applied_at FROM tn_migrations ORDER BY migration');
        return $statement->fetchAll(PDO::FETCH_ASSOC);
    }

    private function isApplied(string $name): bool
    {
        $statement = $this->connection->prepare('SELECT 1 FROM tn_migrations WHERE migration = :migration LIMIT 1');
        $statement->execute(['migration' => $name]);
        $rows = $statement->fetchAll(PDO::FETCH_NUM);
        $applied = $rows !== [];
        $statement->closeCursor();

        return $applied;
    }
}
