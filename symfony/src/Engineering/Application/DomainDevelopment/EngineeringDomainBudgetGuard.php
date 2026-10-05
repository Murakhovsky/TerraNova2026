<?php
declare(strict_types=1);

namespace App\Engineering\Application\DomainDevelopment;

use App\Engineering\Application\Persistence\EngineeringDomainStoreInterface;
use App\Engineering\Application\Service\EngineeringStatusService;

final readonly class EngineeringDomainBudgetGuard
{
    public function __construct(
        private EngineeringDomainStoreInterface $domains,
        private EngineeringStatusService $engineering,
    ) {}

    /** @param array<string,mixed> $context @return array{allowed:bool,reasons:list<string>,evidence:array<string,mixed>} */
    public function agentRunDecision(string $domainId, array $context): array
    {
        $domain = $this->domains->domain($domainId);
        $usage = $this->usage($domainId);
        $extensionFactor = 1 + $this->approvedExtensions($domainId);

        $contextBudget = max(1, (int) ($domain['context_budget'] ?? 120000)) * $extensionFactor;
        $tokenBudget = max(1, (int) ($domain['token_budget'] ?? 1000000)) * $extensionFactor;
        $costBudget = max(0.0, (float) ($domain['cost_budget'] ?? 25.0)) * $extensionFactor;
        $encoded = json_encode($context, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $contextSize = strlen($encoded);

        $reasons = [];
        if ($contextSize > $contextBudget) {
            $reasons[] = sprintf('Context budget exceeded: %d > %d bytes.', $contextSize, $contextBudget);
        }
        if ($usage['tokens'] >= $tokenBudget) {
            $reasons[] = sprintf('Domain token budget exhausted: %d >= %d.', $usage['tokens'], $tokenBudget);
        }
        if ($usage['cost'] >= $costBudget) {
            $reasons[] = sprintf('Domain cost budget exhausted: %.6f >= %.6f.', $usage['cost'], $costBudget);
        }

        return [
            'allowed' => $reasons === [],
            'reasons' => $reasons,
            'evidence' => [
                'context_bytes' => $contextSize,
                'context_budget' => $contextBudget,
                'tokens_used' => $usage['tokens'],
                'token_budget' => $tokenBudget,
                'cost_used' => round($usage['cost'], 8),
                'cost_budget' => round($costBudget, 8),
                'approved_budget_extensions' => $extensionFactor - 1,
            ],
        ];
    }

    /** @return array{tokens:int,cost:float} */
    public function usage(string $domainId): array
    {
        $tokens = 0;
        $cost = 0.0;
        foreach ($this->domains->agentRuns($domainId) as $run) {
            $usage = is_array($run['usage'] ?? null) ? $run['usage'] : [];
            $runTokens = isset($usage['total_tokens']) && is_numeric($usage['total_tokens'])
                ? max(0, (int) $usage['total_tokens'])
                : max(0, (int) ($usage['input_tokens'] ?? 0)) + max(0, (int) ($usage['output_tokens'] ?? 0));
            $tokens += $runTokens;
            if (isset($usage['cost_amount']) && is_numeric($usage['cost_amount'])) {
                $cost += max(0.0, (float) $usage['cost_amount']);
            }
        }

        foreach ($this->domains->features($domainId) as $feature) {
            $featureId = $feature['engineering_feature_id'] ?? null;
            if (!is_string($featureId) || trim($featureId) === '') continue;
            try {
                $status = $this->engineering->status($featureId);
            } catch (\Throwable) {
                continue;
            }
            $usage = is_array($status['usage'] ?? null) ? $status['usage'] : [];
            if (isset($usage['total_tokens']) && is_numeric($usage['total_tokens'])) {
                $tokens += max(0, (int) $usage['total_tokens']);
            }
            if (isset($usage['cost_amount']) && is_numeric($usage['cost_amount'])) {
                $cost += max(0.0, (float) $usage['cost_amount']);
            }
        }

        return ['tokens' => $tokens, 'cost' => $cost];
    }

    private function approvedExtensions(string $domainId): int
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
