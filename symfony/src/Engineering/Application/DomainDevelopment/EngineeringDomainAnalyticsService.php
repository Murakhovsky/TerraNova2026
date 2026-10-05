<?php
declare(strict_types=1);

namespace App\Engineering\Application\DomainDevelopment;

use App\Engineering\Application\Persistence\EngineeringDomainStoreInterface;
use App\Engineering\Application\Service\EngineeringStatusService;
use App\Engineering\Domain\DomainDevelopment\EngineeringDomainArtifactType;

final readonly class EngineeringDomainAnalyticsService
{
    public function __construct(
        private EngineeringDomainStoreInterface $domains,
        private EngineeringStatusService $engineering,
    ) {}

    /** @return array<string,mixed> */
    public function snapshot(string $domainId): array
    {
        $domain = $this->domains->domain($domainId);
        $runs = $this->domains->agentRuns($domainId);

        $domainTokens = 0;
        $domainCost = 0.0;
        $byRole = [];
        foreach ($runs as $run) {
            $usage = is_array($run['usage'] ?? null) ? $run['usage'] : [];
            $tokens = 0;
            foreach (['input_tokens','cached_input_tokens','output_tokens','reasoning_tokens'] as $field) {
                if (isset($usage[$field]) && is_numeric($usage[$field])) $tokens += max(0, (int) $usage[$field]);
            }
            $cost = isset($usage['cost_amount']) && is_numeric($usage['cost_amount'])
                ? max(0.0, (float) $usage['cost_amount'])
                : 0.0;
            $domainTokens += $tokens;
            $domainCost += $cost;

            $role = (string) ($run['agent_role'] ?? 'UNKNOWN');
            $byRole[$role] ??= ['runs' => 0, 'failed' => 0, 'tokens' => 0, 'cost' => 0.0];
            ++$byRole[$role]['runs'];
            if (($run['status'] ?? null) !== 'COMPLETED') ++$byRole[$role]['failed'];
            $byRole[$role]['tokens'] += $tokens;
            $byRole[$role]['cost'] += $cost;
        }
        ksort($byRole);
        foreach ($byRole as &$bucket) $bucket['cost'] = round((float) $bucket['cost'], 8);
        unset($bucket);

        $featureUsage = [];
        $featureTokens = 0;
        $featureCost = 0.0;
        foreach ($this->domains->features($domainId) as $feature) {
            $featureId = $feature['engineering_feature_id'] ?? null;
            if (!is_string($featureId) || trim($featureId) === '') continue;
            try {
                $status = $this->engineering->status($featureId);
                $final = $status['artifacts']['FINAL_REPORT']['content'] ?? [];
                $metrics = is_array($final['metrics'] ?? null) ? $final['metrics'] : [];
                $tokens = is_numeric($metrics['total_tokens'] ?? null) ? max(0, (int) $metrics['total_tokens']) : 0;
                $cost = is_numeric($metrics['total_cost'] ?? null) ? max(0.0, (float) $metrics['total_cost']) : 0.0;
                $featureTokens += $tokens;
                $featureCost += $cost;
                $featureUsage[] = [
                    'feature_key' => $feature['feature_key'] ?? null,
                    'engineering_feature_id' => $featureId,
                    'status' => $feature['status'] ?? null,
                    'tokens' => $tokens,
                    'cost' => round($cost, 8),
                    'agent_runs' => (int) ($metrics['total_agent_runs'] ?? 0),
                    'review_cycles' => (int) ($metrics['review_cycles'] ?? 0),
                    'qa_cycles' => (int) ($metrics['qa_cycles'] ?? 0),
                    'architecture_revalidations' => (int) ($metrics['architecture_revalidations'] ?? 0),
                ];
            } catch (\Throwable $error) {
                $featureUsage[] = [
                    'feature_key' => $feature['feature_key'] ?? null,
                    'engineering_feature_id' => $featureId,
                    'status' => $feature['status'] ?? null,
                    'analytics_error' => $error->getMessage(),
                ];
            }
        }

        $architectureHistory = array_map(static fn (array $artifact): array => [
            'artifact_id' => $artifact['id'] ?? null,
            'version' => $artifact['version'] ?? null,
            'status' => $artifact['status'] ?? null,
            'content_hash' => $artifact['content_hash'] ?? null,
            'supersedes_artifact_id' => $artifact['supersedes_artifact_id'] ?? null,
            'created_by' => $artifact['created_by'] ?? null,
            'created_at' => $artifact['created_at'] ?? null,
            'repository_revision' => $artifact['content']['repository_revision'] ?? null,
            'repository_context_index_hash' => $artifact['content']['repository_context_index_hash'] ?? null,
        ], $this->domains->artifactHistory($domainId, EngineeringDomainArtifactType::DOMAIN_ARCHITECTURE->value));

        $totalTokens = $domainTokens + $featureTokens;
        $totalCost = $domainCost + $featureCost;
        $tokenBudget = max(1, (int) ($domain['token_budget'] ?? 1));
        $costBudget = max(0.0, (float) ($domain['cost_budget'] ?? 0.0));

        return [
            'usage' => [
                'domain_agent_tokens' => $domainTokens,
                'domain_agent_cost' => round($domainCost, 8),
                'feature_tokens' => $featureTokens,
                'feature_cost' => round($featureCost, 8),
                'total_tokens' => $totalTokens,
                'total_cost' => round($totalCost, 8),
                'token_budget' => $tokenBudget,
                'cost_budget' => $costBudget,
                'token_budget_utilization' => round($totalTokens / $tokenBudget, 6),
                'cost_budget_utilization' => $costBudget > 0 ? round($totalCost / $costBudget, 6) : null,
            ],
            'by_role' => $byRole,
            'by_feature' => $featureUsage,
            'architecture_history' => $architectureHistory,
        ];
    }
}
