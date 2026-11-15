<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);
require $root.'/vendor/autoload.php';

use Infrastructure\Llm\ZaiChatCompletionsStructuredLlmClient;
use Kernel\Llm\LlmProviderException;
use Kernel\Llm\StructuredLlmRequest;
use Platform\Settings\Contract\PlatformSettingsReaderInterface;

$settings = new class implements PlatformSettingsReaderInterface {
    public function value(string $organizationId, string $namespace, string $key, mixed $fallback = null): mixed
    { return $fallback; }
    public function secret(string $organizationId, string $namespace, string $key, ?string $fallback = null): ?string
    { return $key === 'zai.api_key' ? 'fixture-key' : $fallback; }
    public function namespace(string $organizationId, string $namespace): array
    { return []; }
};

$request = new StructuredLlmRequest(
    systemPrompt: 'Return valid JSON.',
    userPrompt: 'Say hello.',
    context: ['source' => 'untrusted'],
    responseSchema: ['type' => 'object', 'properties' => ['answer' => ['type' => 'string']], 'required' => ['answer']],
    model: 'glm-4.7-flash',
    organizationId: 'test-org',
);

$attempts = 0;
$success = json_encode([
    'request_id' => 'zai-test-123',
    'model' => 'glm-4.7-flash',
    'choices' => [['finish_reason' => 'stop', 'message' => ['role' => 'assistant', 'content' => '{"answer":"Працює"}']]],
    'usage' => ['prompt_tokens' => 14, 'completion_tokens' => 9, 'prompt_tokens_details' => ['cached_tokens' => 3]],
], JSON_THROW_ON_ERROR);

$client = new ZaiChatCompletionsStructuredLlmClient(
    settings: $settings,
    transport: static function (string $body, string $key) use (&$attempts, $success): array {
        $attempts++;
        if ($key !== 'fixture-key') throw new RuntimeException('Missing encrypted tenant key.');
        $payload = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
        if (($payload['model'] ?? '') !== 'glm-4.7-flash'
            || ($payload['response_format']['type'] ?? '') !== 'json_object'
            || ($payload['messages'][0]['role'] ?? '') !== 'system'
            || ($payload['messages'][1]['role'] ?? '') !== 'user'
            || ($payload['stream'] ?? true) !== false
        ) throw new RuntimeException('Invalid Z.AI API payload.');
        return [200, $success];
    },
);

$result = $client->complete($request);
if ($attempts !== 1 || $result->provider !== 'zai' || $result->output['answer'] !== 'Працює'
    || $result->inputTokens !== 14 || $result->outputTokens !== 9 || $result->cachedInputTokens !== 3
    || $result->providerRequestId !== 'zai-test-123'
) throw new RuntimeException('Z.AI structured response mapping failed.');

$retryCount = 0;
$retryClient = new ZaiChatCompletionsStructuredLlmClient(
    settings: $settings,
    transport: static function (string $body, string $key) use (&$retryCount, $success): array {
        $retryCount++;
        return $retryCount === 1 ? [429, '{}'] : [200, $success];
    },
);
$retryClient->complete($request);
if ($retryCount !== 2) throw new RuntimeException('Z.AI rate-limit retry failed.');

$badClient = new ZaiChatCompletionsStructuredLlmClient(
    settings: $settings,
    transport: static fn (string $body, string $key): array => [401, '{"error":"invalid key"}'],
);
try {
    $badClient->complete($request);
    throw new RuntimeException('Expected Z.AI auth failure.');
} catch (LlmProviderException $e) {
    if (str_contains($e->getMessage(), 'invalid key')) throw new RuntimeException('Provider error body leaked.');
}

echo "Z.AI structured LLM adapter contract passed.\n";
