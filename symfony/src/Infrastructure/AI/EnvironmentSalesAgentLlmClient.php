<?php
declare(strict_types=1);

namespace App\Infrastructure\AI;

use Infrastructure\Llm\HttpStructuredLlmClient;
use Kernel\Agent\AgentDefinition;
use Kernel\Agent\Contract\OrganizationAwareLlmClientInterface;
use Kernel\Agent\LlmResponse;

final class EnvironmentSalesAgentLlmClient implements OrganizationAwareLlmClientInterface
{
    private ?HttpStructuredLlmClient $http = null;

    public function __construct(
        private readonly string $mode,
        private readonly string $endpoint,
        private readonly string $token,
        private readonly string $model,
        private readonly string $provider,
    ) {
    }

    public function structured(AgentDefinition $agent, string $question, array $context): LlmResponse
    {
        return $this->execute($agent, $question, $context);
    }

    public function structuredForOrganization(
        string $organizationId,
        AgentDefinition $agent,
        string $question,
        array $context,
        ?string $correlationId = null,
    ): LlmResponse {
        return $this->execute($agent, $question, $context);
    }

    private function execute(AgentDefinition $agent, string $question, array $context): LlmResponse
    {
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

        $this->http ??= new HttpStructuredLlmClient(
            $this->endpoint,
            $this->token,
            $this->model,
            $this->provider !== '' ? $this->provider : 'http',
        );

        return $this->http->structured($agent, $question, $context);
    }
}
