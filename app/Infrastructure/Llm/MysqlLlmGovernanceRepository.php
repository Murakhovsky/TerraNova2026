<?php
declare(strict_types=1);

namespace Infrastructure\Llm;

use Kernel\Llm\LlmGovernanceRepositoryInterface;
use Kernel\Llm\LlmUsageRecord;
use PDO;

final readonly class MysqlLlmGovernanceRepository implements LlmGovernanceRepositoryInterface
{
    public function __construct(private PDO $connection)
    {
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
}
