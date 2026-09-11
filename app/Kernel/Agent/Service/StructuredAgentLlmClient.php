<?php
declare(strict_types=1);

namespace Kernel\Agent\Service;

use Kernel\Agent\AgentDefinition;
use Kernel\Agent\Contract\OrganizationAwareLlmClientInterface;
use Kernel\Agent\LlmResponse;
use Kernel\Llm\StructuredLlmClientInterface;
use Kernel\Llm\StructuredLlmRequest;

final readonly class StructuredAgentLlmClient implements OrganizationAwareLlmClientInterface
{
    public function __construct(private StructuredLlmClientInterface $client)
    {
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
        $response = $this->client->complete(new StructuredLlmRequest(
            systemPrompt: $agent->systemPrompt,
            userPrompt: $question,
            context: $context,
            responseSchema: $this->responseSchema($agent),
            model: $agent->model,
            organizationId: $organizationId,
            useCase: $this->useCase($agent),
            correlationId: $correlationId,
        ));

        return new LlmResponse(
            $response->output,
            $response->provider,
            $response->model,
            $response->inputTokens,
            $response->outputTokens,
            $response->costAmount,
            $response->costCurrency,
        );
    }

    private function useCase(AgentDefinition $agent): string
    {
        $name = strtolower((string) preg_replace('/[^A-Za-z0-9_.:-]+/', '_', trim($agent->name)));
        if ($name === '' || !ctype_alpha($name[0])) {
            $name = 'runtime_' . ltrim($name, '_.:-0123456789');
        }
        return 'agent.' . ($name !== '' ? $name : 'runtime');
    }

    private function responseSchema(AgentDefinition $agent): array
    {
        return [
            'type' => 'object',
            'required' => ['decision', 'reason', 'confidence', 'proposed_actions'],
            'properties' => [
                'decision' => ['type' => 'string'],
                'reason' => ['type' => 'string'],
                'confidence' => ['type' => 'number', 'minimum' => 0, 'maximum' => 1],
                'proposed_actions' => [
                    'type' => 'array',
                    'maxItems' => max(0, $agent->maxActionsPerRun),
                    'items' => [
                        'type' => 'object',
                        'required' => ['type', 'parameters'],
                        'properties' => [
                            'type' => $agent->allowedActionTypes === []
                                ? ['type' => 'string']
                                : ['type' => 'string', 'enum' => $agent->allowedActionTypes],
                            'parameters' => ['type' => 'object'],
                            'target_type' => ['type' => ['string', 'null']],
                            'target_id' => ['type' => ['string', 'null']],
                        ],
                        'additionalProperties' => false,
                    ],
                ],
                'evidence' => [
                    'type' => 'array',
                    'maxItems' => 20,
                    'items' => ['type' => ['string', 'object']],
                ],
            ],
            'additionalProperties' => false,
        ];
    }
}
