<?php
declare(strict_types=1);

namespace App\Engineering\Application\DomainDevelopment;

use App\Engineering\Application\Persistence\EngineeringAgentRunStoreInterface;
use App\Engineering\Application\Persistence\EngineeringDomainStoreInterface;
use App\Engineering\Application\Persistence\EngineeringFeatureStoreInterface;
use App\Engineering\Application\Persistence\EngineeringHumanDecisionStoreInterface;

final readonly class EngineeringFeatureBudgetGuard
{
    public function __construct(
        private EngineeringFeatureStoreInterface $features,
        private EngineeringAgentRunStoreInterface $runs,
        private EngineeringHumanDecisionStoreInterface $humanDecisions,
        private EngineeringDomainStoreInterface $domains,
    ) {}

    /** @return array{allowed:bool,reasons:list<string>,evidence:array<string,mixed>} */
    public function decision(string $featureId): array
    {
        $request = $this->features->request($featureId);
        $budget = is_array($request->metadata['resource_budget'] ?? null) ? $request->metadata['resource_budget'] : [];
        $domainId = trim((string) ($budget['domain_id'] ?? $request->metadata['domain_id'] ?? ''));
        if ($domainId === '') {
            return ['allowed' => true, 'reasons' => [], 'evidence' => ['mode' => 'STANDALONE_FEATURE']];
        }

        $domain = $this->domains->domain($domainId);
        $featureFactor = 1 + $this->featureExtensions($featureId);
        $domainFactor = 1 + $this->domainExtensions($domainId);

        $featureTokenBudget = max(1, (int) ($budget['feature_token_budget'] ?? $domain['token_budget'] ?? 1000000)) * $featureFactor;
        $featureCostBudget = max(0.0, (float) ($budget['feature_cost_budget'] ?? $domain['cost_budget'] ?? 25.0)) * $featureFactor;
        $domainTokenBudget = max(1, (int) ($domain['token_budget'] ?? 1000000)) * $domainFactor;
        $domainCostBudget = max(0.0, (float) ($domain['cost_budget'] ?? 25.0)) * $domainFactor;

        $featureUsage = $this->featureUsage($featureId);
        $domainUsage = $this->domainUsage($domainId);

        $reasons = [];
        if ($featureUsage['tokens'] >= $featureTokenBudget) {
            $reasons[] = sprintf('Feature token budget exhausted: %d >= %d.', $featureUsage['tokens'], $featureTokenBudget);
        }
        if ($featureUsage['cost'] >= $featureCostBudget) {
            $reasons[] = sprintf('Feature cost budget exhausted: %.6f >= %.6f.', $featureUsage['cost'], $featureCostBudget);
        }
        if ($domainUsage['tokens'] >= $domainTokenBudget) {
            $reasons[] = sprintf('Domain total token budget exhausted: %d >= %d.', $domainUsage['tokens'], $domainTokenBudget);
        }
        if ($domainUsage['cost'] >= $domainCostBudget) {
            $reasons[] = sprintf('Domain total cost budget exhausted: %.6f >= %.6f.', $domainUsage['cost'], $domainCostBudget);
        }

        return [
            'allowed' => $reasons === [],
            'reasons' => $reasons,
            'evidence' => [
                'domain_id' => $domainId,
                'feature_tokens_used' => $featureUsage['tokens'],
                'feature_token_budget' => $featureTokenBudget,
                'feature_cost_used' => round($featureUsage['cost'], 8),
                'feature_cost_budget' => round($featureCostBudget, 8),
                'domain_tokens_used' => $domainUsage['tokens'],
                'domain_token_budget' => $domainTokenBudget,
                'domain_cost_used' => round($domainUsage['cost'], 8),
                'domain_cost_budget' => round($domainCostBudget, 8),
                'feature_budget_extensions' => $featureFactor - 1,
                'domain_budget_extensions' => $domainFactor - 1,
                'agent_run_token_budget' => max(1, (int) ($budget['agent_run_token_budget'] ?? 4000)) * $featureFactor,
                'agent_run_cost_budget' => max(0.0, (float) ($budget['agent_run_cost_budget'] ?? 0.25)) * $featureFactor,
                'context_budget' => max(1000, (int) ($budget['context_budget'] ?? 120000)),
            ],
        ];
    }

    /** @return array{tokens:int,cost:float} */
    private function featureUsage(string $featureId): array
    {
        $tokens = 0;
        $cost = 0.0;
        foreach ($this->runs->forFeature($featureId) as $run) {
            $tokens += max(0, (int) ($run['tokens_input'] ?? 0));
            $tokens += max(0, (int) ($run['tokens_output'] ?? 0));
            if (isset($run['cost']) && is_numeric($run['cost'])) $cost += max(0.0, (float) $run['cost']);
        }
        return ['tokens' => $tokens, 'cost' => $cost];
    }

    /** @return array{tokens:int,cost:float} */
    private function domainUsage(string $domainId): array
    {
        $tokens = 0;
        $cost = 0.0;

        foreach ($this->domains->agentRuns($domainId) as $run) {
            $usage = is_array($run['usage'] ?? null) ? $run['usage'] : [];
            foreach (['input_tokens','cached_input_tokens','output_tokens','reasoning_tokens'] as $field) {
                if (isset($usage[$field]) && is_numeric($usage[$field])) $tokens += max(0, (int) $usage[$field]);
            }
            if (isset($usage['cost_amount']) && is_numeric($usage['cost_amount'])) $cost += max(0.0, (float) $usage['cost_amount']);
        }

        foreach ($this->domains->features($domainId) as $feature) {
            $engineeringFeatureId = $feature['engineering_feature_id'] ?? null;
            if (!is_string($engineeringFeatureId) || trim($engineeringFeatureId) === '') continue;
            $usage = $this->featureUsage($engineeringFeatureId);
            $tokens += $usage['tokens'];
            $cost += $usage['cost'];
        }

        return ['tokens' => $tokens, 'cost' => $cost];
    }

    private function featureExtensions(string $featureId): int
    {
        $extensions = 0;
        foreach ($this->humanDecisions->historyForFeature($featureId) as $decision) {
            if (($decision['type'] ?? null) !== 'RESOURCE_BUDGET') continue;
            if (($decision['status'] ?? null) !== 'ANSWERED') continue;
            $selected = strtoupper(trim((string) ($decision['answer']['selected_option'] ?? '')));
            if ($selected === 'CONTINUE') ++$extensions;
        }
        return $extensions;
    }

    private function domainExtensions(string $domainId): int
    {
        $extensions = 0;
        foreach ($this->domains->humanDecisionHistory($domainId) as $decision) {
            if (($decision['gate_type'] ?? null) !== 'DOMAIN_RESOURCE_BUDGET') continue;
            if (($decision['status'] ?? null) !== 'ANSWERED') continue;
            $selected = strtoupper(trim((string) ($decision['answer']['selected_option'] ?? '')));
            if (in_array($selected, ['APPROVE','CONTINUE'], true)) ++$extensions;
        }
        return $extensions;
    }
}
