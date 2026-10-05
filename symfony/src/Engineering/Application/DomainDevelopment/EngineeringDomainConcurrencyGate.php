<?php
declare(strict_types=1);

namespace App\Engineering\Application\DomainDevelopment;

use App\Engineering\Application\Persistence\EngineeringDomainStoreInterface;
use App\Engineering\Application\Persistence\EngineeringFeatureStoreInterface;
use App\Engineering\Application\Persistence\EngineeringWorkflowStoreInterface;
use App\Engineering\Domain\Agent\AgentRole;
use App\Engineering\Domain\Workflow\EngineeringWorkflowState;

final readonly class EngineeringDomainConcurrencyGate
{
    public function __construct(
        private EngineeringDomainStoreInterface $domains,
        private EngineeringFeatureStoreInterface $features,
        private EngineeringWorkflowStoreInterface $workflows,
    ) {}

    /** @return array{allowed:bool,scope:string,limit:int,active:int,contenders:list<string>} */
    public function decision(string $featureId, AgentRole $role): array
    {
        $request = $this->features->request($featureId);
        $domainId = trim((string) ($request->metadata['domain_id'] ?? ''));
        if ($request->sourceType !== 'domain_runtime' || $domainId === '') {
            return ['allowed' => true, 'scope' => 'FEATURE', 'limit' => PHP_INT_MAX, 'active' => 0, 'contenders' => []];
        }

        $stage = $this->stage($role);
        if ($stage === null) {
            return ['allowed' => true, 'scope' => 'DOMAIN', 'limit' => PHP_INT_MAX, 'active' => 0, 'contenders' => []];
        }

        $domain = $this->domains->domain($domainId);
        $limit = max(1, (int) ($domain[$stage['limit_field']] ?? 1));
        $contenders = [];

        foreach ($this->domains->features($domainId) as $domainFeature) {
            $childFeatureId = $domainFeature['engineering_feature_id'] ?? null;
            if (!is_string($childFeatureId) || trim($childFeatureId) === '') continue;
            $workflowId = $this->workflows->activeIdForFeature($childFeatureId);
            if ($workflowId === null) continue;

            try {
                $state = $this->workflows->get($workflowId)->currentState();
            } catch (\Throwable) {
                continue;
            }
            if (!in_array($state, $stage['states'], true)) continue;
            $contenders[(string) ($domainFeature['feature_key'] ?? $childFeatureId)] = $childFeatureId;
        }

        ksort($contenders, SORT_STRING);
        $ordered = array_values($contenders);
        $allowedIds = array_slice($ordered, 0, $limit);

        return [
            'allowed' => in_array($featureId, $allowedIds, true),
            'scope' => $stage['scope'],
            'limit' => $limit,
            'active' => count($ordered),
            'contenders' => $ordered,
        ];
    }

    /** @return array{scope:string,limit_field:string,states:list<EngineeringWorkflowState>}|null */
    private function stage(AgentRole $role): ?array
    {
        return match ($role) {
            AgentRole::DEVELOPER => [
                'scope' => 'DEVELOPMENT',
                'limit_field' => 'max_parallel_developers',
                'states' => [EngineeringWorkflowState::DEVELOPMENT_RUNNING],
            ],
            AgentRole::REVIEWER => [
                'scope' => 'REVIEW',
                'limit_field' => 'max_parallel_reviews',
                'states' => [EngineeringWorkflowState::REVIEW_PENDING],
            ],
            AgentRole::QA_PLANNER,
            AgentRole::QA_EXECUTOR,
            AgentRole::QA => [
                'scope' => 'QA',
                'limit_field' => 'max_parallel_qa',
                'states' => [EngineeringWorkflowState::QA_PLANNING, EngineeringWorkflowState::QA_PENDING],
            ],
            default => null,
        };
    }
}
