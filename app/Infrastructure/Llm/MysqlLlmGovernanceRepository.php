<?php
declare(strict_types=1);

namespace Infrastructure\Llm;

use Kernel\Execution\ExecutionFailureException;
use Kernel\Llm\LlmBudgetExceededException;
use Kernel\Llm\LlmBudgetReservation;
use Kernel\Llm\LlmGovernanceRepositoryInterface;
use Kernel\Llm\LlmUsageRecord;
use Kernel\Resilience\Contract\CircuitBreakerStoreInterface;
use PDO;
use RuntimeException;
use Throwable;

final readonly class MysqlLlmGovernanceRepository implements LlmGovernanceRepositoryInterface, CircuitBreakerStoreInterface
{
    private const RESERVATION_TTL_MINUTES = 60;
    private const GLOBAL_ORGANIZATION = '_global';

    public function __construct(
        private PDO $connection,
        private int $budgetLockTimeoutSeconds = 10,
    ) {
        if ($this->budgetLockTimeoutSeconds < 0 || $this->budgetLockTimeoutSeconds > 60) {
            throw new \InvalidArgumentException('LLM budget lock timeout must be between 0 and 60 seconds.');
        }
    }

    public function monthlyBudget(string $organizationId, string $currency): ?float
    {
        $statement = $this->connection->prepare(
            'SELECT monthly_limit FROM cos_llm_budgets WHERE organization_id = :organization_id AND currency = :currency LIMIT 1'
        );
        $statement->execute(['organization_id' => $organizationId, 'currency' => strtoupper($currency)]);
        $value = $statement->fetchColumn();
        return $value === false ? null : (float) $value;
    }

    public function monthlySpend(string $organizationId, string $currency): float
    {
        $statement = $this->connection->prepare(
            'SELECT COALESCE(SUM(cost_amount), 0) FROM cos_llm_usage '
            . 'WHERE organization_id = :organization_id AND cost_currency = :currency '
            . 'AND created_at >= DATE_FORMAT(UTC_TIMESTAMP(), "%Y-%m-01 00:00:00") '
            . 'AND created_at < DATE_ADD(DATE_FORMAT(UTC_TIMESTAMP(), "%Y-%m-01 00:00:00"), INTERVAL 1 MONTH)'
        );
        $statement->execute(['organization_id' => $organizationId, 'currency' => strtoupper($currency)]);
        return (float) $statement->fetchColumn();
    }

    public function reserveBudget(string $organizationId, float $maxCostAmount, string $currency): LlmBudgetReservation
    {
        $currency = strtoupper($currency);
        return $this->synchronizedBudget($organizationId, $currency, function () use ($organizationId, $maxCostAmount, $currency): LlmBudgetReservation {
            $this->purgeExpiredReservations($organizationId, $currency);
            $limit = $this->monthlyBudget($organizationId, $currency);
            if ($limit === null) {
                throw new RuntimeException(sprintf('Cannot reserve an undefined LLM budget for organization %s.', $organizationId));
            }

            $spent = $this->monthlySpend($organizationId, $currency);
            $reserved = $this->activeReservedAmount($organizationId, $currency);
            if ($spent + $reserved + $maxCostAmount > $limit) {
                throw new LlmBudgetExceededException($organizationId, $spent + $reserved, $limit, $currency);
            }

            $reservation = new LlmBudgetReservation(bin2hex(random_bytes(16)), $organizationId, $maxCostAmount, $currency);
            $statement = $this->connection->prepare(
                'INSERT INTO cos_llm_budget_reservations '
                . '(id, organization_id, currency, reserved_amount, expires_at, created_at) '
                . 'VALUES (:id, :organization_id, :currency, :reserved_amount, '
                . sprintf('DATE_ADD(UTC_TIMESTAMP(6), INTERVAL %d MINUTE), UTC_TIMESTAMP(6))', self::RESERVATION_TTL_MINUTES)
            );
            $statement->execute([
                'id' => $reservation->id,
                'organization_id' => $reservation->organizationId,
                'currency' => $reservation->currency,
                'reserved_amount' => $reservation->maxCostAmount,
            ]);
            return $reservation;
        });
    }

    public function settleBudget(LlmBudgetReservation $reservation, LlmUsageRecord $usage): void
    {
        $this->synchronizedBudget($reservation->organizationId, $reservation->currency, function () use ($reservation, $usage): void {
            $this->connection->beginTransaction();
            try {
                $statement = $this->connection->prepare(
                    'SELECT reserved_amount FROM cos_llm_budget_reservations '
                    . 'WHERE id = :id AND organization_id = :organization_id AND currency = :currency FOR UPDATE'
                );
                $statement->execute([
                    'id' => $reservation->id,
                    'organization_id' => $reservation->organizationId,
                    'currency' => $reservation->currency,
                ]);
                if ($statement->fetchColumn() === false) {
                    throw new RuntimeException(sprintf('LLM budget reservation %s is not active.', $reservation->id));
                }

                $this->record($usage);
                $delete = $this->connection->prepare('DELETE FROM cos_llm_budget_reservations WHERE id = :id');
                $delete->execute(['id' => $reservation->id]);
                $this->connection->commit();
            } catch (Throwable $error) {
                if ($this->connection->inTransaction()) {
                    $this->connection->rollBack();
                }
                throw $error;
            }
        });
    }

    public function releaseBudget(LlmBudgetReservation $reservation): void
    {
        $this->synchronizedBudget($reservation->organizationId, $reservation->currency, function () use ($reservation): void {
            $statement = $this->connection->prepare(
                'DELETE FROM cos_llm_budget_reservations WHERE id = :id AND organization_id = :organization_id AND currency = :currency'
            );
            $statement->execute([
                'id' => $reservation->id,
                'organization_id' => $reservation->organizationId,
                'currency' => $reservation->currency,
            ]);
        });
    }

    public function synchronizedBudget(string $organizationId, string $currency, callable $operation): mixed
    {
        $currency = strtoupper($currency);
        $lockName = 'cos.llm.' . substr(hash('sha256', $organizationId . '|' . $currency), 0, 40);
        $statement = $this->connection->prepare(sprintf('SELECT GET_LOCK(:lock_name, %d)', $this->budgetLockTimeoutSeconds));
        $statement->execute(['lock_name' => $lockName]);
        if ((int) $statement->fetchColumn() !== 1) {
            throw new RuntimeException(sprintf('Unable to acquire LLM budget lock for organization %s within %d seconds.', $organizationId, $this->budgetLockTimeoutSeconds));
        }

        try {
            return $operation();
        } finally {
            $release = $this->connection->prepare('SELECT RELEASE_LOCK(:lock_name)');
            $release->execute(['lock_name' => $lockName]);
            if ((int) $release->fetchColumn() !== 1) {
                throw new RuntimeException(sprintf('Unable to release LLM budget lock for organization %s.', $organizationId));
            }
        }
    }

    public function record(LlmUsageRecord $usage): void
    {
        $statement = $this->connection->prepare(
            'INSERT INTO cos_llm_usage '
            . '(id, organization_id, correlation_id, use_case, provider, model, input_tokens, output_tokens, cost_amount, cost_currency, latency_ms, fallback_count, created_at) '
            . 'VALUES (:id, :organization_id, :correlation_id, :use_case, :provider, :model, :input_tokens, :output_tokens, :cost_amount, :cost_currency, :latency_ms, :fallback_count, UTC_TIMESTAMP(6))'
        );
        $statement->execute([
            'id' => $usage->id,
            'organization_id' => $usage->organizationId,
            'correlation_id' => $usage->correlationId,
            'use_case' => $usage->useCase,
            'provider' => $usage->provider,
            'model' => $usage->model,
            'input_tokens' => $usage->inputTokens,
            'output_tokens' => $usage->outputTokens,
            'cost_amount' => $usage->costAmount,
            'cost_currency' => $usage->costCurrency !== null ? strtoupper($usage->costCurrency) : null,
            'latency_ms' => $usage->latencyMs,
            'fallback_count' => $usage->fallbackCount,
        ]);
    }

    public function assertAvailable(?string $organizationId, string $serviceKey): void
    {
        $statement = $this->connection->prepare(
            'SELECT opened_until FROM cos_external_circuits '
            . 'WHERE organization_id = :organization_id AND service_key = :service_key LIMIT 1'
        );
        $statement->execute([
            'organization_id' => $this->circuitOrganizationKey($organizationId),
            'service_key' => $serviceKey,
        ]);
        $openedUntil = $statement->fetchColumn();
        if ($openedUntil === false || $openedUntil === null) {
            return;
        }

        $check = $this->connection->prepare('SELECT :opened_until > UTC_TIMESTAMP(6)');
        $check->execute(['opened_until' => $openedUntil]);
        if ((int) $check->fetchColumn() === 1) {
            throw ExecutionFailureException::externalUnavailable(
                sprintf('External circuit is open for %s.', $serviceKey),
            );
        }

        $this->recordSuccess($organizationId, $serviceKey);
    }

    public function recordSuccess(?string $organizationId, string $serviceKey): void
    {
        $statement = $this->connection->prepare(
            'INSERT INTO cos_external_circuits '
            . '(organization_id, service_key, consecutive_failures, opened_until, updated_at) '
            . 'VALUES (:organization_id, :service_key, 0, NULL, UTC_TIMESTAMP(6)) '
            . 'ON DUPLICATE KEY UPDATE consecutive_failures = 0, opened_until = NULL, updated_at = UTC_TIMESTAMP(6)'
        );
        $statement->execute([
            'organization_id' => $this->circuitOrganizationKey($organizationId),
            'service_key' => $serviceKey,
        ]);
    }

    public function recordRetryableFailure(
        ?string $organizationId,
        string $serviceKey,
        int $failureThreshold,
        int $openSeconds,
    ): void {
        $failureThreshold = max(1, $failureThreshold);
        $openSeconds = max(1, min($openSeconds, 86400));
        $statement = $this->connection->prepare(
            'INSERT INTO cos_external_circuits '
            . '(organization_id, service_key, consecutive_failures, opened_until, updated_at) '
            . 'VALUES (:organization_id, :service_key, 1, '
            . ($failureThreshold <= 1
                ? sprintf('DATE_ADD(UTC_TIMESTAMP(6), INTERVAL %d SECOND)', $openSeconds)
                : 'NULL')
            . ', UTC_TIMESTAMP(6)) '
            . 'ON DUPLICATE KEY UPDATE '
            . 'opened_until = IF(consecutive_failures + 1 >= ' . $failureThreshold . ', '
            . sprintf('DATE_ADD(UTC_TIMESTAMP(6), INTERVAL %d SECOND)', $openSeconds)
            . ', opened_until), consecutive_failures = consecutive_failures + 1, updated_at = UTC_TIMESTAMP(6)'
        );
        $statement->execute([
            'organization_id' => $this->circuitOrganizationKey($organizationId),
            'service_key' => $serviceKey,
        ]);
    }

    private function activeReservedAmount(string $organizationId, string $currency): float
    {
        $statement = $this->connection->prepare(
            'SELECT COALESCE(SUM(reserved_amount), 0) FROM cos_llm_budget_reservations '
            . 'WHERE organization_id = :organization_id AND currency = :currency AND expires_at > UTC_TIMESTAMP(6)'
        );
        $statement->execute(['organization_id' => $organizationId, 'currency' => $currency]);
        return (float) $statement->fetchColumn();
    }

    private function purgeExpiredReservations(string $organizationId, string $currency): void
    {
        $statement = $this->connection->prepare(
            'DELETE FROM cos_llm_budget_reservations '
            . 'WHERE organization_id = :organization_id AND currency = :currency AND expires_at <= UTC_TIMESTAMP(6)'
        );
        $statement->execute(['organization_id' => $organizationId, 'currency' => $currency]);
    }

    private function circuitOrganizationKey(?string $organizationId): string
    {
        $organizationId = trim((string) $organizationId);
        return $organizationId !== '' ? $organizationId : self::GLOBAL_ORGANIZATION;
    }
}
