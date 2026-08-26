<?php
declare(strict_types=1);

namespace Infrastructure\Database\Migration;

use PDO;
use RuntimeException;

final readonly class MigrationRunner
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
        if ((int) $this->connection->query("SELECT GET_LOCK('cos_schema_migrations', 30)")->fetchColumn() !== 1) {
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
                foreach ($this->splitter->split($sql) as $statement) {
                    $this->connection->exec($statement);
                }
                if (!$this->isApplied($name)) {
                    $insert = $this->connection->prepare('INSERT INTO tn_migrations (migration) VALUES (:migration)');
                    $insert->execute(['migration' => $name]);
                }
                $result['applied'][] = $name;
            }
            return $result;
        } finally {
            $this->connection->query("SELECT RELEASE_LOCK('cos_schema_migrations')");
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
        return $statement->fetchColumn() !== false;
    }
}
