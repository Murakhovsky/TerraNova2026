<?php
declare(strict_types=1);

namespace Infrastructure\AI;

use Kernel\Agent\AgentDefinition;
use Kernel\Agent\Contract\LlmProviderInterface;
use Kernel\Agent\Model\AgentContext;
use Kernel\Agent\Model\AgentOutput;
use Kernel\Agent\Service\AgentOutputSchemaFactory;
use Kernel\Llm\StructuredLlmClientInterface;
use Kernel\Llm\StructuredLlmRequest;

final readonly class StructuredLlmAgentProvider implements LlmProviderInterface
{
    public function __construct(
        private StructuredLlmClientInterface $client,
        private AgentOutputSchemaFactory $schemas = new AgentOutputSchemaFactory(),
    ) {
    }

    public function execute(AgentDefinition $definition, AgentContext $context): AgentOutput
    {
        $question = trim((string) ($context->input['question'] ?? $context->input['prompt'] ?? ''));
        if ($question === '') {
            $question = 'Execute the agent task using the supplied input and context.';
        }

        $response = $this->client->complete(new StructuredLlmRequest(
            systemPrompt: $definition->systemPrompt,
            userPrompt: $question,
            context: [
                'input' => $context->input,
                'data' => $context->data,
                'metadata' => $context->metadata,
                'requested_by' => $context->requestedBy?->value(),
            ],
            responseSchema: $this->schemas->create($definition),
            model: $definition->model,
            organizationId: $context->organizationId->value(),
            useCase: 'agent.run',
            correlationId: $context->correlationId,
        ));

        $content = '';
        foreach (['reason', 'decision', 'message'] as $field) {
            if (isset($response->output[$field]) && is_scalar($response->output[$field])) {
                $content = (string) $response->output[$field];
                break;
            }
        }

        return new AgentOutput(
            content: $content,
            structured: $response->output,
            provider: $response->provider,
            model: $response->model,
            usage: array_filter([
                'input_tokens' => $response->inputTokens,
                'output_tokens' => $response->outputTokens,
                'cost_amount' => $response->costAmount,
                'cost_currency' => $response->costCurrency,
            ], static fn (mixed $value): bool => $value !== null),
            metadata: [
                'organization_id' => $context->organizationId->value(),
                'correlation_id' => $context->correlationId,
            ],
        );
    }
}
