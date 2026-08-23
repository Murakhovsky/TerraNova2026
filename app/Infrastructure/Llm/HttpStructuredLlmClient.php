<?php
declare(strict_types=1);

namespace Infrastructure\Llm;

use Kernel\Agent\AgentDefinition;
use Kernel\Agent\Contract\LlmClientInterface;
use Kernel\Agent\LlmResponse;
use RuntimeException;

final readonly class HttpStructuredLlmClient implements LlmClientInterface
{
    public function __construct(
        private string $endpoint,
        private string $token,
        private string $model,
        private string $provider = 'http',
        private int $timeoutSeconds = 45,
    ) {}

    public function structured(AgentDefinition $agent, string $question, array $context): LlmResponse
    {
        if ($this->endpoint === '') {
            throw new RuntimeException('LLM_ENDPOINT is not configured.');
        }

        $body = json_encode([
            'model' => $this->model,
            'system_prompt' => $agent->systemPrompt,
            'question' => $question,
            'context' => $context,
            'response_schema' => [
                'type' => 'object',
                'required' => ['decision', 'reason', 'confidence', 'proposed_actions'],
                'properties' => [
                    'decision' => ['type' => 'string'],
                    'reason' => ['type' => 'string'],
                    'confidence' => ['type' => 'number', 'minimum' => 0, 'maximum' => 1],
                    'proposed_actions' => ['type' => 'array'],
                    'evidence' => ['type' => 'array'],
                ],
                'additionalProperties' => false,
            ],
        ], JSON_THROW_ON_ERROR);

        $curl = curl_init($this->endpoint);
        curl_setopt_array($curl, [
            CURLOPT_POST => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => $this->timeoutSeconds,
            CURLOPT_HTTPHEADER => array_values(array_filter([
                'Content-Type: application/json',
                $this->token !== '' ? 'Authorization: Bearer ' . $this->token : null,
            ])),
            CURLOPT_POSTFIELDS => $body,
        ]);
        $raw = curl_exec($curl);
        $status = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
        $error = curl_error($curl);
        curl_close($curl);

        if (!is_string($raw) || $status < 200 || $status >= 300) {
            throw new RuntimeException(sprintf('LLM request failed (%d): %s', $status, $error ?: $raw));
        }

        $decoded = json_decode($raw, true, flags: JSON_THROW_ON_ERROR);
        $output = $decoded['output'] ?? $decoded;
        if (!is_array($output)) {
            throw new RuntimeException('LLM response does not contain structured output.');
        }

        return new LlmResponse(
            $output,
            (string) ($decoded['provider'] ?? $this->provider),
            (string) ($decoded['model'] ?? $this->model),
            isset($decoded['usage']['input_tokens']) ? (int) $decoded['usage']['input_tokens'] : null,
            isset($decoded['usage']['output_tokens']) ? (int) $decoded['usage']['output_tokens'] : null,
            isset($decoded['cost']['amount']) ? (float) $decoded['cost']['amount'] : null,
            isset($decoded['cost']['currency']) ? (string) $decoded['cost']['currency'] : null,
        );
    }
}
