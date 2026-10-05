<?php
declare(strict_types=1);

namespace App\Engineering\Infrastructure\Observability;

use App\Engineering\Application\Observability\EngineeringObservabilityReadModelInterface;
use Doctrine\ORM\EntityManagerInterface;
use Kernel\Llm\LlmPricingResolverInterface;
use Throwable;

final readonly class DoctrineEngineeringObservabilityReadModel implements EngineeringObservabilityReadModelInterface
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private LlmPricingResolverInterface $pricing,
    ) {}

    public function usageForFeature(string $featureId): array
    {
        return $this->usageForFeatures([$featureId])[$featureId] ?? $this->unknownUsage();
    }

    public function usageForWorkflow(string $workflowId): array
    {
        try {
            $rows = $this->withEstimatedCosts($this->ledgerRowsForWorkflow($workflowId));
            if ($rows !== []) return $this->aggregate($rows, 'LLM_LEDGER');

            $fallback = $this->entityManager->getConnection()->fetchAssociative(
                "SELECT
                    SUM(CASE WHEN tokens_input IS NOT NULL THEN tokens_input ELSE 0 END) AS input_tokens,
                    SUM(CASE WHEN tokens_output IS NOT NULL THEN tokens_output ELSE 0 END) AS output_tokens,
                    SUM(CASE WHEN estimated_cost IS NOT NULL THEN estimated_cost ELSE 0 END) AS cost_amount,
                    SUM(tokens_input IS NOT NULL OR tokens_output IS NOT NULL) AS token_records,
                    SUM(estimated_cost IS NOT NULL) AS cost_records,
                    COUNT(*) AS run_count
                 FROM cos_engineering_agent_runs
                 WHERE workflow_execution_id = :workflow_id",
                ['workflow_id' => $workflowId],
            );
            if (!is_array($fallback) || (int) ($fallback['run_count'] ?? 0) === 0) return $this->unknownUsage();

            $tokenKnown = (int) ($fallback['token_records'] ?? 0) > 0;
            $costKnown = (int) ($fallback['cost_records'] ?? 0) > 0
                && (int) ($fallback['cost_records'] ?? 0) === (int) ($fallback['run_count'] ?? 0);
            $input = $tokenKnown ? (int) ($fallback['input_tokens'] ?? 0) : null;
            $output = $tokenKnown ? (int) ($fallback['output_tokens'] ?? 0) : null;

            return [
                'available' => $tokenKnown || $costKnown,
                'source' => 'AGENT_RUN_FALLBACK',
                'invocations' => 0,
                'input_tokens' => $input,
                'cached_input_tokens' => null,
                'output_tokens' => $output,
                'reasoning_tokens' => null,
                'total_tokens' => $tokenKnown ? $input + $output : null,
                'cost_amount' => $costKnown ? (float) ($fallback['cost_amount'] ?? 0) : null,
                'cost_currency' => $costKnown ? 'USD' : null,
                'cost_complete' => $costKnown,
                'cost_sources' => $costKnown ? ['AGENT_RUN_FALLBACK'] : [],
                'pricing_versions' => [],
                'models' => [],
            ];
        } catch (Throwable) {
            return $this->unknownUsage();
        }
    }

    public function usageForFeatures(array $featureIds): array
    {
        $featureIds = $this->ids($featureIds);
        $usage = [];
        foreach ($featureIds as $featureId) $usage[$featureId] = $this->unknownUsage();
        if ($featureIds === []) return $usage;

        try {
            [$in, $params] = $this->inParams($featureIds, 'feature');
            $rows = $this->entityManager->getConnection()->fetchAllAssociative(
                "SELECT traces.feature_id, u.organization_id, u.id, u.correlation_id, u.use_case, u.provider, u.model,
                        u.input_tokens, u.cached_input_tokens, u.output_tokens, u.reasoning_tokens,
                        u.cost_amount, u.cost_currency, u.cost_source, u.pricing_version, u.provider_request_id,
                        u.latency_ms, u.fallback_count, u.created_at
                 FROM (
                    SELECT DISTINCT feature_id, trace_id
                    FROM cos_engineering_agent_runs
                    WHERE feature_id IN ($in) AND trace_id <> ''
                 ) traces
                 INNER JOIN cos_llm_usage u ON u.correlation_id = traces.trace_id
                 ORDER BY u.created_at ASC, u.id ASC",
                $params,
            );

            $rows = $this->withEstimatedCosts($rows);
            $byFeature = [];
            foreach ($rows as $row) {
                $featureId = (string) ($row['feature_id'] ?? '');
                if ($featureId !== '') $byFeature[$featureId][] = $row;
            }
            foreach ($byFeature as $featureId => $featureRows) {
                $usage[$featureId] = $this->aggregate($featureRows, 'LLM_LEDGER');
            }

            $fallbackRows = $this->entityManager->getConnection()->fetchAllAssociative(
                "SELECT feature_id,
                        SUM(CASE WHEN tokens_input IS NOT NULL THEN tokens_input ELSE 0 END) AS input_tokens,
                        SUM(CASE WHEN tokens_output IS NOT NULL THEN tokens_output ELSE 0 END) AS output_tokens,
                        SUM(CASE WHEN estimated_cost IS NOT NULL THEN estimated_cost ELSE 0 END) AS cost_amount,
                        SUM(tokens_input IS NOT NULL OR tokens_output IS NOT NULL) AS token_records,
                        SUM(estimated_cost IS NOT NULL) AS cost_records,
                        COUNT(*) AS run_count
                 FROM cos_engineering_agent_runs
                 WHERE feature_id IN ($in)
                 GROUP BY feature_id",
                $params,
            );
            foreach ($fallbackRows as $row) {
                $featureId = (string) ($row['feature_id'] ?? '');
                if ($featureId === '' || (($usage[$featureId]['invocations'] ?? 0) > 0)) continue;
                $tokenKnown = (int) ($row['token_records'] ?? 0) > 0;
                $costKnown = (int) ($row['cost_records'] ?? 0) > 0
                    && (int) ($row['cost_records'] ?? 0) === (int) ($row['run_count'] ?? 0);
                $input = $tokenKnown ? (int) ($row['input_tokens'] ?? 0) : null;
                $output = $tokenKnown ? (int) ($row['output_tokens'] ?? 0) : null;
                $usage[$featureId] = [
                    'available' => $tokenKnown || $costKnown,
                    'source' => 'AGENT_RUN_FALLBACK',
                    'invocations' => 0,
                    'input_tokens' => $input,
                    'cached_input_tokens' => null,
                    'output_tokens' => $output,
                    'reasoning_tokens' => null,
                    'total_tokens' => $tokenKnown ? $input + $output : null,
                    'cost_amount' => $costKnown ? (float) ($row['cost_amount'] ?? 0) : null,
                    'cost_currency' => $costKnown ? 'USD' : null,
                    'cost_complete' => $costKnown,
                    'cost_sources' => $costKnown ? ['AGENT_RUN_FALLBACK'] : [],
                    'pricing_versions' => [],
                    'models' => [],
                ];
            }
        } catch (Throwable) {
        }

        return $usage;
    }

    public function invocationsForFeature(string $featureId): array
    {
        try {
            return $this->normalizeInvocations($this->withEstimatedCosts($this->ledgerRows($featureId)));
        } catch (Throwable) {
            return [];
        }
    }

    public function invocationsForWorkflow(string $workflowId): array
    {
        try {
            return $this->normalizeInvocations($this->withEstimatedCosts($this->ledgerRowsForWorkflow($workflowId)));
        } catch (Throwable) {
            return [];
        }
    }

    public function workspaceFactsForFeatures(array $featureIds): array
    {
        $featureIds = $this->ids($featureIds);
        $facts = [];
        foreach ($featureIds as $featureId) {
            $facts[$featureId] = [
                'tasks_total' => 0,
                'tasks_completed' => 0,
                'agent_runs' => 0,
                'latest_agent_role' => null,
                'latest_agent_status' => null,
                'latest_agent_started_at' => null,
                'usage' => $this->unknownUsage(),
            ];
        }
        if ($featureIds === []) return $facts;

        try {
            [$in, $params] = $this->inParams($featureIds, 'fact');
            $tasks = $this->entityManager->getConnection()->fetchAllAssociative(
                "SELECT feature_id, COUNT(*) AS total,
                        SUM(CASE WHEN status = 'COMPLETED' THEN 1 ELSE 0 END) AS completed
                 FROM cos_engineering_tasks WHERE feature_id IN ($in) GROUP BY feature_id",
                $params,
            );
            foreach ($tasks as $row) {
                $id = (string) $row['feature_id'];
                if (!isset($facts[$id])) continue;
                $facts[$id]['tasks_total'] = (int) $row['total'];
                $facts[$id]['tasks_completed'] = (int) $row['completed'];
            }

            $runs = $this->entityManager->getConnection()->fetchAllAssociative(
                "SELECT feature_id, COUNT(*) AS run_count
                 FROM cos_engineering_agent_runs
                 WHERE feature_id IN ($in)
                 GROUP BY feature_id",
                $params,
            );
            foreach ($runs as $row) {
                $id = (string) $row['feature_id'];
                if (isset($facts[$id])) $facts[$id]['agent_runs'] = (int) $row['run_count'];
            }

            $latest = $this->entityManager->getConnection()->fetchAllAssociative(
                "SELECT ar.feature_id, ar.agent_role, ar.status, ar.started_at
                 FROM cos_engineering_agent_runs ar
                 INNER JOIN (
                    SELECT feature_id, MAX(started_at) AS started_at
                    FROM cos_engineering_agent_runs
                    WHERE feature_id IN ($in)
                    GROUP BY feature_id
                 ) latest ON latest.feature_id = ar.feature_id AND latest.started_at = ar.started_at
                 WHERE ar.feature_id IN ($in)",
                $params,
            );
            foreach ($latest as $row) {
                $id = (string) $row['feature_id'];
                if (!isset($facts[$id])) continue;
                $facts[$id]['latest_agent_role'] = (string) $row['agent_role'];
                $facts[$id]['latest_agent_status'] = (string) $row['status'];
                $facts[$id]['latest_agent_started_at'] = (string) $row['started_at'];
            }

            $usage = $this->usageForFeatures($featureIds);
            foreach ($usage as $id => $row) if (isset($facts[$id])) $facts[$id]['usage'] = $row;
        } catch (Throwable) {
        }

        return $facts;
    }

    /** @return list<array<string,mixed>> */
    private function ledgerRows(string $featureId): array
    {
        return $this->entityManager->getConnection()->fetchAllAssociative(
            "SELECT u.organization_id, u.id, u.correlation_id, u.use_case, u.provider, u.model,
                    u.input_tokens, u.cached_input_tokens, u.output_tokens, u.reasoning_tokens,
                    u.cost_amount, u.cost_currency, u.cost_source, u.pricing_version, u.provider_request_id,
                    u.latency_ms, u.fallback_count, u.created_at
             FROM cos_llm_usage u
             WHERE u.correlation_id IN (
                SELECT DISTINCT trace_id FROM cos_engineering_agent_runs
                WHERE feature_id = :feature_id AND trace_id <> ''
             )
             ORDER BY u.created_at ASC, u.id ASC",
            ['feature_id' => $featureId],
        );
    }

    /** @return list<array<string,mixed>> */
    private function ledgerRowsForWorkflow(string $workflowId): array
    {
        return $this->entityManager->getConnection()->fetchAllAssociative(
            "SELECT u.organization_id, u.id, u.correlation_id, u.use_case, u.provider, u.model,
                    u.input_tokens, u.cached_input_tokens, u.output_tokens, u.reasoning_tokens,
                    u.cost_amount, u.cost_currency, u.cost_source, u.pricing_version, u.provider_request_id,
                    u.latency_ms, u.fallback_count, u.created_at
             FROM cos_llm_usage u
             WHERE u.correlation_id IN (
                SELECT DISTINCT trace_id FROM cos_engineering_agent_runs
                WHERE workflow_execution_id = :workflow_id AND trace_id <> ''
             )
             ORDER BY u.created_at ASC, u.id ASC",
            ['workflow_id' => $workflowId],
        );
    }

    /**
     * Backfill cost at read time for historical ledger rows created before a pricing
     * snapshot existed. Persisted provider cost always wins.
     *
     * @param list<array<string,mixed>> $rows
     * @return list<array<string,mixed>>
     */
    private function withEstimatedCosts(array $rows): array
    {
        foreach ($rows as &$row) {
            if (($row['cost_amount'] ?? null) !== null) continue;

            $estimate = $this->pricing->estimate(
                isset($row['organization_id']) ? (string) $row['organization_id'] : null,
                (string) ($row['provider'] ?? ''),
                (string) ($row['model'] ?? ''),
                $row['input_tokens'] !== null ? (int) $row['input_tokens'] : null,
                $row['output_tokens'] !== null ? (int) $row['output_tokens'] : null,
                $row['cached_input_tokens'] !== null ? (int) $row['cached_input_tokens'] : null,
                $row['reasoning_tokens'] !== null ? (int) $row['reasoning_tokens'] : null,
            );
            if ($estimate === null) continue;

            $row['cost_amount'] = $estimate->amount;
            $row['cost_currency'] = $estimate->currency;
            $row['cost_source'] = 'READ_MODEL_'.$estimate->source;
            $row['pricing_version'] = $estimate->pricingVersion;
        }
        unset($row);

        return $rows;
    }

    /** @param list<array<string,mixed>> $rows @return list<array<string,mixed>> */
    private function normalizeInvocations(array $rows): array
    {
        return array_map(static fn (array $row): array => [
            'id' => (string) ($row['id'] ?? ''),
            'correlation_id' => (string) ($row['correlation_id'] ?? ''),
            'use_case' => $row['use_case'] !== null ? (string) $row['use_case'] : null,
            'provider' => (string) ($row['provider'] ?? ''),
            'model' => (string) ($row['model'] ?? ''),
            'input_tokens' => $row['input_tokens'] !== null ? (int) $row['input_tokens'] : null,
            'cached_input_tokens' => $row['cached_input_tokens'] !== null ? (int) $row['cached_input_tokens'] : null,
            'output_tokens' => $row['output_tokens'] !== null ? (int) $row['output_tokens'] : null,
            'reasoning_tokens' => $row['reasoning_tokens'] !== null ? (int) $row['reasoning_tokens'] : null,
            'total_tokens' => ($row['input_tokens'] !== null || $row['output_tokens'] !== null)
                ? (int) ($row['input_tokens'] ?? 0) + (int) ($row['output_tokens'] ?? 0)
                : null,
            'cost_amount' => $row['cost_amount'] !== null ? (float) $row['cost_amount'] : null,
            'cost_currency' => $row['cost_currency'] !== null ? (string) $row['cost_currency'] : null,
            'cost_source' => $row['cost_source'] !== null ? (string) $row['cost_source'] : null,
            'pricing_version' => $row['pricing_version'] !== null ? (string) $row['pricing_version'] : null,
            'provider_request_id' => $row['provider_request_id'] !== null ? (string) $row['provider_request_id'] : null,
            'latency_ms' => $row['latency_ms'] !== null ? (int) $row['latency_ms'] : null,
            'fallback_count' => (int) ($row['fallback_count'] ?? 0),
            'created_at' => (string) ($row['created_at'] ?? ''),
        ], $rows);
    }

    /** @param list<array<string,mixed>> $rows @return array<string,mixed> */
    private function aggregate(array $rows, string $source): array
    {
        $input = 0;
        $cached = 0;
        $output = 0;
        $reasoning = 0;
        $tokenKnown = false;
        $cost = 0.0;
        $costComplete = $rows !== [];
        $currencies = [];
        $costSources = [];
        $pricingVersions = [];
        $models = [];

        foreach ($rows as $row) {
            if ($row['input_tokens'] !== null) {
                $input += (int) $row['input_tokens'];
                $tokenKnown = true;
            }
            if ($row['cached_input_tokens'] !== null) {
                $cached += (int) $row['cached_input_tokens'];
            }
            if ($row['output_tokens'] !== null) {
                $output += (int) $row['output_tokens'];
                $tokenKnown = true;
            }
            if ($row['reasoning_tokens'] !== null) {
                $reasoning += (int) $row['reasoning_tokens'];
            }
            if ($row['cost_amount'] === null) {
                $costComplete = false;
            } else {
                $cost += (float) $row['cost_amount'];
                if ($row['cost_currency'] !== null) $currencies[(string) $row['cost_currency']] = true;
                if (($row['cost_source'] ?? null) !== null) $costSources[(string) $row['cost_source']] = true;
                if (($row['pricing_version'] ?? null) !== null) $pricingVersions[(string) $row['pricing_version']] = true;
            }
            $key = (string) ($row['provider'] ?? 'unknown').' / '.(string) ($row['model'] ?? 'unknown');
            $models[$key] = ($models[$key] ?? 0) + 1;
        }

        return [
            'available' => $rows !== [],
            'source' => $source,
            'invocations' => count($rows),
            'input_tokens' => $tokenKnown ? $input : null,
            'cached_input_tokens' => $tokenKnown ? $cached : null,
            'output_tokens' => $tokenKnown ? $output : null,
            'reasoning_tokens' => $tokenKnown ? $reasoning : null,
            'total_tokens' => $tokenKnown ? $input + $output : null,
            'cost_amount' => $costComplete ? round($cost, 6) : null,
            'cost_currency' => $costComplete && count($currencies) === 1 ? array_key_first($currencies) : null,
            'cost_complete' => $costComplete,
            'cost_sources' => array_keys($costSources),
            'pricing_versions' => array_keys($pricingVersions),
            'models' => $models,
        ];
    }

    /** @return array<string,mixed> */
    private function unknownUsage(): array
    {
        return [
            'available' => false,
            'source' => 'UNAVAILABLE',
            'invocations' => 0,
            'input_tokens' => null,
            'cached_input_tokens' => null,
            'output_tokens' => null,
            'reasoning_tokens' => null,
            'total_tokens' => null,
            'cost_amount' => null,
            'cost_currency' => null,
            'cost_complete' => false,
            'cost_sources' => [],
            'pricing_versions' => [],
            'models' => [],
        ];
    }

    /** @param list<string> $ids @return list<string> */
    private function ids(array $ids): array
    {
        return array_values(array_unique(array_filter(array_map(
            static fn (mixed $id): string => trim((string) $id),
            $ids,
        ), static fn (string $id): bool => $id !== '')));
    }

    /** @param list<string> $ids @return array{0:string,1:array<string,string>} */
    private function inParams(array $ids, string $prefix): array
    {
        $params = [];
        $placeholders = [];
        foreach ($ids as $index => $id) {
            $key = $prefix.$index;
            $params[$key] = $id;
            $placeholders[] = ':'.$key;
        }
        return [implode(',', $placeholders), $params];
    }
}
