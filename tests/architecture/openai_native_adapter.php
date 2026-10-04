<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);
$adapter = (string) file_get_contents($root.'/app/Infrastructure/Llm/OpenAiResponsesStructuredLlmClient.php');
$services = (string) file_get_contents($root.'/symfony/config/services.yaml');
$docs = (string) file_get_contents($root.'/docs/06-ai-agents/llm-boundary.md');

foreach ([
    'StructuredLlmClientInterface',
    'https://api.openai.com/v1/responses',
    "'type' => 'json_schema'",
    "'strict' => true",
    "'max_output_tokens'",
    "'input_tokens'",
    "'output_tokens'",
    'UNTRUSTED_CONTEXT_JSON',
] as $needle) {
    if (!str_contains($adapter, $needle)) {
        throw new RuntimeException('Native OpenAI Responses adapter missing '.$needle);
    }
}

foreach ([
    'Infrastructure\\Llm\\OpenAiResponsesStructuredLlmClient',
    "openai: '@Infrastructure\\Llm\\OpenAiResponsesStructuredLlmClient'",
    "\$provider: '%env(LLM_PROVIDER)%'",
] as $needle) {
    if (!str_contains($services, $needle)) {
        throw new RuntimeException('Native OpenAI service wiring missing '.$needle);
    }
}

if (!str_contains($docs, 'LLM_PROVIDER=openai')) {
    throw new RuntimeException('Native OpenAI adapter documentation is missing.');
}

echo "Native OpenAI adapter architecture contract passed.\n";
