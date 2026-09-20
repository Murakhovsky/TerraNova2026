<?php
declare(strict_types=1);

namespace App\Infrastructure\Migration\Database;

use JsonException;
use PDO;
use RuntimeException;
use Throwable;

final readonly class PlatformOperationsDatabaseCutover
{
    public const CUTOVER_ID = 'platform-operations-v1';

    private const TABLES = [
        'external_circuits',
        'operational_metrics',
        'llm_budgets',
        'llm_usage',
        'llm_budget_reservations',
    ];

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

            $this->copyExternalCircuits($source['external_circuits']['rows']);
            $this->copyOperationalMetrics($source['operational_metrics']['rows']);
            $this->copyLlmBudgets($source['llm_budgets']['rows']);
            $this->copyLlmUsage($source['llm_usage']['rows']);
            $this->copyLlmBudgetReservations($source['llm_budget_reservations']['rows']);

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
        return [
            'cutover_id' => self::CUTOVER_ID,
            'status' => 'READY',
            'target' => $this->withoutRows($this->snapshot($this->canonical)),
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
        $definitions = [
            'external_circuits' => 'SELECT organization_id,service_key,consecutive_failures,opened_until,updated_at FROM cos_external_circuits ORDER BY organization_id,service_key',
            'operational_metrics' => 'SELECT id,organization_id,metric,value,labels,recorded_at FROM cos_operational_metrics ORDER BY id',
            'llm_budgets' => 'SELECT organization_id,currency,monthly_limit,updated_at FROM cos_llm_budgets ORDER BY organization_id,currency',
            'llm_usage' => 'SELECT id,organization_id,correlation_id,use_case,provider,model,input_tokens,output_tokens,cost_amount,cost_currency,latency_ms,fallback_count,created_at FROM cos_llm_usage ORDER BY id',
            'llm_budget_reservations' => 'SELECT id,organization_id,currency,reserved_amount,expires_at,created_at FROM cos_llm_budget_reservations ORDER BY id',
        ];

        $snapshot = ['total' => 0];
        foreach ($definitions as $key => $sql) {
            $rows = $this->rows($connection, $sql);
            $snapshot[$key] = [
                'count' => count($rows),
                'hash' => $this->hashRows($rows),
                'rows' => $rows,
            ];
            $snapshot['total'] += count($rows);
        }

        return $snapshot;
    }

    /** @return list<array<string,mixed>> */
    private function rows(PDO $connection, string $sql): array
    {
        $rows = $connection->query($sql)->fetchAll(PDO::FETCH_ASSOC);
        return is_array($rows) ? $rows : [];
    }

    /** @param list<array<string,mixed>> $rows */
    private function copyExternalCircuits(array $rows): void
    {
        $statement = $this->canonical->prepare(
            'INSERT INTO cos_external_circuits '
            . '(organization_id,service_key,consecutive_failures,opened_until,updated_at) '
            . 'VALUES (:organization_id,:service_key,:consecutive_failures,:opened_until,:updated_at) '
            . 'ON DUPLICATE KEY UPDATE consecutive_failures=VALUES(consecutive_failures),'
            . 'opened_until=VALUES(opened_until),updated_at=VALUES(updated_at)'
        );
        $this->executeRows($statement, $rows);
    }

    /** @param list<array<string,mixed>> $rows */
    private function copyOperationalMetrics(array $rows): void
    {
        $statement = $this->canonical->prepare(
            'INSERT INTO cos_operational_metrics (id,organization_id,metric,value,labels,recorded_at) '
            . 'VALUES (:id,:organization_id,:metric,:value,:labels,:recorded_at) '
            . 'ON DUPLICATE KEY UPDATE organization_id=VALUES(organization_id),metric=VALUES(metric),'
            . 'value=VALUES(value),labels=VALUES(labels),recorded_at=VALUES(recorded_at)'
        );
        $this->executeRows($statement, $rows);
    }

    /** @param list<array<string,mixed>> $rows */
    private function copyLlmBudgets(array $rows): void
    {
        $statement = $this->canonical->prepare(
            'INSERT INTO cos_llm_budgets (organization_id,currency,monthly_limit,updated_at) '
            . 'VALUES (:organization_id,:currency,:monthly_limit,:updated_at) '
            . 'ON DUPLICATE KEY UPDATE monthly_limit=VALUES(monthly_limit),updated_at=VALUES(updated_at)'
        );
        $this->executeRows($statement, $rows);
    }

    /** @param list<array<string,mixed>> $rows */
    private function copyLlmUsage(array $rows): void
    {
        $statement = $this->canonical->prepare(
            'INSERT INTO cos_llm_usage '
            . '(id,organization_id,correlation_id,use_case,provider,model,input_tokens,output_tokens,cost_amount,cost_currency,latency_ms,fallback_count,created_at) '
            . 'VALUES (:id,:organization_id,:correlation_id,:use_case,:provider,:model,:input_tokens,:output_tokens,:cost_amount,:cost_currency,:latency_ms,:fallback_count,:created_at) '
            . 'ON DUPLICATE KEY UPDATE organization_id=VALUES(organization_id),correlation_id=VALUES(correlation_id),'
            . 'use_case=VALUES(use_case),provider=VALUES(provider),model=VALUES(model),input_tokens=VALUES(input_tokens),'
            . 'output_tokens=VALUES(output_tokens),cost_amount=VALUES(cost_amount),cost_currency=VALUES(cost_currency),'
            . 'latency_ms=VALUES(latency_ms),fallback_count=VALUES(fallback_count),created_at=VALUES(created_at)'
        );
        $this->executeRows($statement, $rows);
    }

    /** @param list<array<string,mixed>> $rows */
    private function copyLlmBudgetReservations(array $rows): void
    {
        $statement = $this->canonical->prepare(
            'INSERT INTO cos_llm_budget_reservations '
            . '(id,organization_id,currency,reserved_amount,expires_at,created_at) '
            . 'VALUES (:id,:organization_id,:currency,:reserved_amount,:expires_at,:created_at) '
            . 'ON DUPLICATE KEY UPDATE organization_id=VALUES(organization_id),currency=VALUES(currency),'
            . 'reserved_amount=VALUES(reserved_amount),expires_at=VALUES(expires_at),created_at=VALUES(created_at)'
        );
        $this->executeRows($statement, $rows);
    }

    /** @param list<array<string,mixed>> $rows */
    private function executeRows(\PDOStatement $statement, array $rows): void
    {
        foreach ($rows as $row) {
            $statement->execute($row);
        }
    }

    /** @param array<string,mixed> $source @param array<string,mixed> $target */
    private function assertEquivalent(array $source, array $target): void
    {
        foreach (self::TABLES as $key) {
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
        foreach (self::TABLES as $key) {
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
