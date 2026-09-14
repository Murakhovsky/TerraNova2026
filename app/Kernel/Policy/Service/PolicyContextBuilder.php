<?php
declare(strict_types=1);

namespace Kernel\Policy\Service;

use Kernel\Action\ActionProposal;
use Kernel\Module\DomainModuleRegistry;

final readonly class PolicyContextBuilder
{
    public function __construct(private DomainModuleRegistry $domains) {}

    public function build(string $organizationId, ActionProposal $proposal): array
    {
        $role = strtoupper((string) ($proposal->policyContext['actor']['role'] ?? $proposal->sourceType));
        $risk = strtoupper($proposal->riskLevel);
        $rank = ['LOW'=>1,'MEDIUM'=>2,'HIGH'=>3,'CRITICAL'=>4][$risk] ?? 0;
        $base = [
            'action' => [
                'type' => $proposal->type,
                'parameters' => $proposal->parameters,
                'risk_level' => $risk,
                'source_type' => $proposal->sourceType,
                'target_type' => $proposal->targetType,
                'target_id' => $proposal->targetId,
            ],
            'actor' => ['role' => $role, 'type' => strtoupper($proposal->sourceType)],
            'risk' => ['level' => $risk, 'rank' => $rank],
            'confidence' => $proposal->policyContext['confidence'] ?? null,
        ];
        $provider = $this->domains->policyContextProviderFor($proposal->type);
        $domain = $provider?->context($organizationId, $proposal) ?? [];
        return array_replace_recursive($base, $domain, $proposal->policyContext);
    }
}
