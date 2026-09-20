<?php
declare(strict_types=1);

namespace App\Infrastructure\Migration\Database;

use JsonException;
use PDO;
use RuntimeException;
use Throwable;

final readonly class ModuleRuntimeDatabaseCutover
{
    public const CUTOVER_ID = 'module-runtime-v1';

    public function __construct(
        private PDO $legacy,
        private PDO $canonical,
    ) {
    }

    /** @return array<string,mixed> */
    public function migrate(): array
    {
        $journal = $this->journal();
        if (($journal['status'] ?? null) === 'COMPLETED') {
            return [
                'cutover_id' => self::CUTOVER_ID,
                'status' => 'ALREADY_COMPLETED',
                'verification' => $this->verifyCanonical(),
            ];
        }

        $source = $this->snapshot($this->legacy);

        $this->canonical->beginTransaction();
        try {
            $this->recordJournal('RUNNING', $source['total'], 0, [
                'source' => $this->withoutRows($source),
            ]);

            $this->copyOrganizationModules($source['organization_modules']['rows']);
            $this->copyModuleInstallations($source['module_installations']['rows']);

            $target = $this->snapshot($this->canonical);
            $this->assertEquivalent($source, $target);

            $this->recordJournal('COMPLETED', $source['total'], $target['total'], [
                'source' => $this->withoutRows($source),
                'target' => $this->withoutRows($target),
            ], true);

            $this->canonical->commit();

            return [
                'cutover_id' => self::CUTOVER_ID,
                'status' => 'COMPLETED',
                'source' => $this->withoutRows($source),
                'target' => $this->withoutRows($target),
            ];
        } catch (Throwable $error) {
            if ($this->canonical->inTransaction()) {
                $this->canonical->rollBack();
            }
            throw $error;
        }
    }

    /** @return array<string,mixed> */
    public function verifyCanonical(): array
    {
        $target = $this->snapshot($this->canonical);

        return [
            'cutover_id' => self::CUTOVER_ID,
            'status' => 'READY',
            'target' => $this->withoutRows($target),
            'journal' => $this->journal(),
        ];
    }

    /** @return array<string,mixed>|null */
    private function journal(): ?array
    {
        $statement = $this->canonical->prepare(
            'SELECT cutover_id,status,source_rows,target_rows,details_json,started_at,completed_at,updated_at '
            . 'FROM cos_database_cutover_journal WHERE cutover_id=:cutover_id LIMIT 1'
        );
        $statement->execute(['cutover_id' => self::CUTOVER_ID]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);

        return is_array($row) ? $row : null;
    }

    /** @return array<string,mixed> */
    private function snapshot(PDO $connection): array
    {
        $organizationModules = $this->rows(
            $connection,
            'SELECT organization_id,module_id,enabled,configuration_json,created_at,updated_at '
            . 'FROM cos_organization_modules ORDER BY organization_id,module_id'
        );
        $moduleInstallations = $this->rows(
            $connection,
            'SELECT organization_id,module_id,status,installed_version,schema_version,installed_at,updated_at '
            . 'FROM cos_module_installations ORDER BY organization_id,module_id'
        );

        return [
            'organization_modules' => [
                'count' => count($organizationModules),
                'hash' => $this->hashRows($organizationModules),
                'rows' => $organizationModules,
            ],
            'module_installations' => [
                'count' => count($moduleInstallations),
                'hash' => $this->hashRows($moduleInstallations),
                'rows' => $moduleInstallations,
            ],
            'total' => count($organizationModules) + count($moduleInstallations),
        ];
    }

    /** @return list<array<string,mixed>> */
    private function rows(PDO $connection, string $sql): array
    {
        $rows = $connection->query($sql)->fetchAll(PDO::FETCH_ASSOC);

        return is_array($rows) ? $rows : [];
    }

    /** @param list<array<string,mixed>> $rows */
    private function copyOrganizationModules(array $rows): void
    {
        $statement = $this->canonical->prepare(
            'INSERT INTO cos_organization_modules '
            . '(organization_id,module_id,enabled,configuration_json,created_at,updated_at) '
            . 'VALUES (:organization_id,:module_id,:enabled,:configuration_json,:created_at,:updated_at) '
            . 'ON DUPLICATE KEY UPDATE enabled=VALUES(enabled),configuration_json=VALUES(configuration_json),'
            . 'created_at=VALUES(created_at),updated_at=VALUES(updated_at)'
        );

        foreach ($rows as $row) {
            $statement->execute($row);
        }
    }

    /** @param list<array<string,mixed>> $rows */
    private function copyModuleInstallations(array $rows): void
    {
        $statement = $this->canonical->prepare(
            'INSERT INTO cos_module_installations '
            . '(organization_id,module_id,status,installed_version,schema_version,installed_at,updated_at) '
            . 'VALUES (:organization_id,:module_id,:status,:installed_version,:schema_version,:installed_at,:updated_at) '
            . 'ON DUPLICATE KEY UPDATE status=VALUES(status),installed_version=VALUES(installed_version),'
            . 'schema_version=VALUES(schema_version),installed_at=VALUES(installed_at),updated_at=VALUES(updated_at)'
        );

        foreach ($rows as $row) {
            $statement->execute($row);
        }
    }

    /** @param array<string,mixed> $source @param array<string,mixed> $target */
    private function assertEquivalent(array $source, array $target): void
    {
        foreach (['organization_modules', 'module_installations'] as $key) {
            if (($source[$key]['count'] ?? null) !== ($target[$key]['count'] ?? null)
                || ($source[$key]['hash'] ?? null) !== ($target[$key]['hash'] ?? null)) {
                throw new RuntimeException(sprintf('Database cutover verification failed for %s.', $key));
            }
        }
    }

    /** @param list<array<string,mixed>> $rows */
    private function hashRows(array $rows): string
    {
        $normalized = array_map(
            static fn (array $row): array => array_map(
                static fn (mixed $value): mixed => $value === null ? null : (string) $value,
                $row,
            ),
            $rows,
        );

        try {
            return hash('sha256', json_encode($normalized, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
        } catch (JsonException $error) {
            throw new RuntimeException('Could not hash database cutover rows.', 0, $error);
        }
    }

    /** @param array<string,mixed> $snapshot @return array<string,mixed> */
    private function withoutRows(array $snapshot): array
    {
        foreach (['organization_modules', 'module_installations'] as $key) {
            unset($snapshot[$key]['rows']);
        }

        return $snapshot;
    }

    /** @param array<string,mixed> $details */
    private function recordJournal(
        string $status,
        int $sourceRows,
        int $targetRows,
        array $details,
        bool $completed = false,
    ): void {
        $statement = $this->canonical->prepare(
            'INSERT INTO cos_database_cutover_journal '
            . '(cutover_id,status,source_rows,target_rows,details_json,completed_at) '
            . 'VALUES (:cutover_id,:status,:source_rows,:target_rows,:details_json,:completed_at) '
            . 'ON DUPLICATE KEY UPDATE status=VALUES(status),source_rows=VALUES(source_rows),'
            . 'target_rows=VALUES(target_rows),details_json=VALUES(details_json),'
            . 'completed_at=VALUES(completed_at),updated_at=CURRENT_TIMESTAMP'
        );
        $statement->execute([
            'cutover_id' => self::CUTOVER_ID,
            'status' => $status,
            'source_rows' => $sourceRows,
            'target_rows' => $targetRows,
            'details_json' => json_encode($details, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
            'completed_at' => $completed ? date('Y-m-d H:i:s') : null,
        ]);
    }
}
