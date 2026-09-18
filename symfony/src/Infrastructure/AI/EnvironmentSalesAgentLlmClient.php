<?php
declare(strict_types=1);

namespace App\Infrastructure\AI;

use Kernel\Agent\AgentDefinition;
use Kernel\Agent\Contract\OrganizationAwareLlmClientInterface;
use Kernel\Agent\LlmResponse;

final readonly class EnvironmentSalesAgentLlmClient implements OrganizationAwareLlmClientInterface
{
    public function __construct(
        private string $mode,
        private OrganizationAwareLlmClientInterface $production,
    ) {
    }

    public function structured(AgentDefinition $agent, string $question, array $context): LlmResponse
    {
        return $this->execute(null, $agent, $question, $context, null);
    }

    public function structuredForOrganization(
        string $organizationId,
        AgentDefinition $agent,
        string $question,
        array $context,
        ?string $correlationId = null,
    ): LlmResponse {
        return $this->execute($organizationId, $agent, $question, $context, $correlationId);
    }

    private function execute(
        ?string $organizationId,
        AgentDefinition $agent,
        string $question,
        array $context,
        ?string $correlationId,
    ): LlmResponse {
        if (strtolower(trim($this->mode)) === 'fixture') {
            $dealId = (string) ($context['deal']['id'] ?? 'unknown');

            return new LlmResponse([
                'decision' => 'MANAGER_REVIEW',
                'reason' => 'Deterministic Wave 4 fixture recommends a governed manager review.',
                'confidence' => 0.92,
                'proposed_actions' => [[
                    'type' => 'sales.request_manager_review',
                    'target_type' => 'deal',
                    'target_id' => $dealId,
                    'parameters' => [
                        'title' => 'Agent review of Sales opportunity',
                        'due_in_minutes' => 60,
                    ],
                ]],
                'evidence' => [
                    'sales_intelligence' => [
                        'deal_health' => 'at_risk',
                        'risk_level' => 'medium',
                        'risk_reasons' => ['deterministic_wave4_fixture'],
                        'opportunity_level' => 'medium',
                        'customer_intent' => 'requires_follow_up',
                        'objections' => [],
                        'missing_information' => [],
                        'next_best_action' => ['type' => 'sales.request_manager_review'],
                        'recommended_timing' => 'now',
                    ],
                ],
            ], 'fixture', 'wave4-deterministic', 10, 20, 0.0, 'USD');
        }

        if ($organizationId !== null) {
            return $this->production->structuredForOrganization(
                $organizationId,
                $agent,
                $question,
                $context,
                $correlationId,
            );
        }

        return $this->production->structured($agent, $question, $context);
    }
}
