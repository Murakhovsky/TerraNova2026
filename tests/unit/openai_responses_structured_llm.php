<?php
declare(strict_types=1);

require dirname(__DIR__, 2).'/vendor/autoload.php';

use Infrastructure\Llm\OpenAiResponsesStructuredLlmClient;
use Kernel\Llm\LlmProviderException;
use Kernel\Llm\StructuredLlmRequest;

$captured = null;
$client = new OpenAiResponsesStructuredLlmClient(
    token: 'test-token',
    model: 'gpt-test',
    transport: static function (string $endpoint, string $token, string $body, int $timeout) use (&$captured): array {
        $captured = [
            'endpoint' => $endpoint,
            'token' => $token,
            'body' => json_decode($body, true, 512, JSON_THROW_ON_ERROR),
            'timeout' => $timeout,
        ];

        return [200, json_encode([
            'model' => 'gpt-test-2026',
            'usage' => ['input_tokens' => 12, 'output_tokens' => 7],
            'output' => [[
                'type' => 'message',
                'content' => [[
                    'type' => 'output_text',
                    'text' => '{"decision":"PASS"}',
                ]],
            ]],
        ], JSON_THROW_ON_ERROR)];
    },
);

$request = new StructuredLlmRequest(
    systemPrompt: 'Return only the requested structured result.',
    userPrompt: 'Evaluate the feature.',
    context: ['feature_id' => 'COS-184'],
    responseSchema: [
        'type' => 'object',
        'properties' => [
            'decision' => ['type' => 'string', 'enum' => ['PASS','FAIL']],
        ],
        'required' => ['decision'],
        'additionalProperties' => false,
    ],
    model: 'gpt-request-model',
    maxOutputTokens: 500,
    organizationId: 'default',
    useCase: 'engineering.qa',
);

$response = $client->complete($request);

if ($response->provider !== 'openai') throw new RuntimeException('OpenAI adapter provider id mismatch.');
if ($response->model !== 'gpt-test-2026') throw new RuntimeException('OpenAI adapter model evidence mismatch.');
if (($response->output['decision'] ?? null) !== 'PASS') throw new RuntimeException('OpenAI structured output was not decoded.');
if ($response->inputTokens !== 12 || $response->outputTokens !== 7) throw new RuntimeException('OpenAI usage was not mapped.');
if (($captured['endpoint'] ?? null) !== 'https://api.openai.com/v1/responses') throw new RuntimeException('OpenAI Responses endpoint mismatch.');
if (($captured['body']['model'] ?? null) !== 'gpt-request-model') throw new RuntimeException('Request-level model routing was not preserved.');
if (($captured['body']['text']['format']['type'] ?? null) !== 'json_schema') throw new RuntimeException('OpenAI Structured Outputs json_schema format is missing.');
if (($captured['body']['text']['format']['strict'] ?? null) !== true) throw new RuntimeException('OpenAI Structured Outputs must be strict.');
if (($captured['body']['text']['format']['schema']['additionalProperties'] ?? null) !== false) throw new RuntimeException('OpenAI response schema was not forwarded.');
if (($captured['body']['max_output_tokens'] ?? null) !== 500) throw new RuntimeException('OpenAI max output token bound was not forwarded.');
if (!str_contains((string) ($captured['body']['input'][0]['content'][0]['text'] ?? ''), 'UNTRUSTED_CONTEXT_JSON')) {
    throw new RuntimeException('OpenAI adapter did not preserve the untrusted-context boundary.');
}


$looseRequest = new StructuredLlmRequest(
    systemPrompt: 'Return the requested engineering structure.',
    userPrompt: 'Plan the feature.',
    context: ['feature_id' => 'COS-185'],
    responseSchema: [
        'type' => 'object',
        'required' => ['feature'],
        'properties' => [
            'feature' => [
                'type' => 'object',
                'properties' => [
                    'title' => ['type' => 'string'],
                ],
                'additionalProperties' => true,
            ],
        ],
        'additionalProperties' => false,
    ],
    model: 'gpt-request-model',
    organizationId: 'default',
    useCase: 'agent.engineering_manager',
);
$loosePayload = $client->payload($looseRequest, 'gpt-request-model');
if (($loosePayload['text']['format']['strict'] ?? null) !== false) {
    throw new RuntimeException('OpenAI adapter must downgrade non-strict-compatible schemas instead of sending invalid strict Structured Outputs.');
}

$retryClient = new OpenAiResponsesStructuredLlmClient(
    token: 'test-token',
    model: 'gpt-test',
    transport: static fn (): array => [429, '{"error":{"message":"rate limited"}}'],
);
try {
    $retryClient->complete($request);
    throw new RuntimeException('OpenAI 429 was accepted.');
} catch (LlmProviderException $error) {
    if (!$error->retryable || $error->httpStatus !== 429) throw $error;
}

$authClient = new OpenAiResponsesStructuredLlmClient(
    token: 'test-token',
    model: 'gpt-test',
    transport: static fn (): array => [401, '{"error":{"message":"invalid token"}}'],
);
try {
    $authClient->complete($request);
    throw new RuntimeException('OpenAI 401 was accepted.');
} catch (LlmProviderException $error) {
    if ($error->retryable || $error->httpStatus !== 401) throw $error;
}

echo "Native OpenAI Responses structured LLM adapter passed.\n";
