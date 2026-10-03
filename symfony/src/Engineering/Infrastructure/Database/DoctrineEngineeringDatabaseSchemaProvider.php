<?php
declare(strict_types=1);

namespace App\Engineering\Infrastructure\Database;

use App\Engineering\Application\Context\EngineeringDatabaseSchemaProviderInterface;
use Doctrine\DBAL\Connection;

final readonly class DoctrineEngineeringDatabaseSchemaProvider implements EngineeringDatabaseSchemaProviderInterface
{
    public function __construct(
        private Connection $connection,
        private int $maxTables = 30,
        private int $maxColumnsPerTable = 60,
    ) {}

    public function snapshot(array $hints = []): array
    {
        $schema = $this->connection->createSchemaManager();
        $tableNames = $schema->listTableNames();
        sort($tableNames);

        $keywords = array_values(array_unique(array_filter(array_map(
            static fn (mixed $hint): string => mb_strtolower(trim((string) $hint)),
            $hints,
        ), static fn (string $hint): bool => $hint !== '')));

        usort($tableNames, static function (string $left, string $right) use ($keywords): int {
            $score = static function (string $table) use ($keywords): int {
                $table = mb_strtolower($table);
                $score = 0;
                foreach ($keywords as $keyword) {
                    if (str_contains($table, $keyword)) $score += 10;
                }
                if (str_starts_with($table, 'cos_')) $score += 2;
                return $score;
            };

            return $score($right) <=> $score($left) ?: strcmp($left, $right);
        });

        $selected = array_slice($tableNames, 0, max(1, $this->maxTables));
        $tables = [];
        foreach ($selected as $tableName) {
            $columns = [];
            foreach (array_slice(array_values($schema->listTableColumns($tableName)), 0, $this->maxColumnsPerTable) as $column) {
                $columns[] = [
                    'name' => $column->getName(),
                    'type' => get_debug_type($column->getType()),
                    'nullable' => !$column->getNotnull(),
                    'length' => $column->getLength(),
                ];
            }

            $indexes = [];
            foreach ($schema->listTableIndexes($tableName) as $index) {
                $indexes[] = [
                    'name' => $index->getName(),
                    'columns' => $index->getColumns(),
                    'unique' => $index->isUnique(),
                    'primary' => $index->isPrimary(),
                ];
            }

            $foreignKeys = [];
            foreach ($schema->listTableForeignKeys($tableName) as $foreignKey) {
                $foreignKeys[] = [
                    'name' => $foreignKey->getName(),
                    'local_columns' => $foreignKey->getLocalColumns(),
                    'foreign_table' => $foreignKey->getForeignTableName(),
                    'foreign_columns' => $foreignKey->getForeignColumns(),
                ];
            }

            $tables[] = [
                'name' => $tableName,
                'columns' => $columns,
                'indexes' => $indexes,
                'foreign_keys' => $foreignKeys,
            ];
        }

        return [
            'read_only' => true,
            'table_count' => count($tableNames),
            'selected_table_count' => count($tables),
            'hints' => $keywords,
            'tables' => $tables,
        ];
    }
}
