<?php
declare(strict_types=1);

namespace Domains\Diagnostic\Infrastructure\AI;

use Domains\Diagnostic\AI\AiGatewayInterface;
use Domains\Diagnostic\AI\AiOperationDefinition;
use Domains\Diagnostic\AI\AiRequest;
use Domains\Diagnostic\AI\AiResponse;
use Domains\Diagnostic\AI\PromptRegistry;
use Kernel\Llm\StructuredLlmClientInterface;
use Kernel\Llm\StructuredLlmRequest;

final readonly class StructuredLlmAiGateway implements AiGatewayInterface
{
    public function __construct(
        private StructuredLlmClientInterface $client,
        private PromptRegistry $prompts = new PromptRegistry(),
    ) {}

    public function execute(AiOperationDefinition $operation, AiRequest $request): AiResponse
    {
        $prompt = $this->prompts->get($operation->promptVersion);
        $started = hrtime(true);
        $response = $this->client->complete(new StructuredLlmRequest(
            systemPrompt: (string) $prompt['system'],
            userPrompt: (string) $prompt['task'],
            context: $request->context,
            responseSchema: $request->outputSchema,
            maxOutputTokens: $operation->tokenBudget,
            organizationId: $request->organizationId,
            useCase: $operation->operationId,
            correlationId: $request->diagnosticId,
        ));

        return new AiResponse(
            $response->output,
            $response->inputTokens ?? 0,
            $response->outputTokens ?? 0,
            $response->costAmount ?? 0.0,
            (int) round((hrtime(true) - $started) / 1_000_000),
            $response->model,
        );
    }
}
