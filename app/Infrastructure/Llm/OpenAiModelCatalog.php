<?php
declare(strict_types=1);

namespace Infrastructure\Llm;

/**
 * Canonical built-in metadata for OpenAI models used by COS.
 *
 * Source snapshot: user-provided OpenAI model/pricing table, 2026-10-05.
 * Tenant Platform Settings and COS_LLM_PRICING_JSON may override pricing.
 */
final class OpenAiModelCatalog
{
    public const VERSION = '2026-10-05';

    /** @return array<string,array<string,mixed>> keyed by provider.model */
    public static function models(): array
    {
        return [
            'openai.gpt-6-astra' => [
                'provider' => 'openai',
                'model' => 'gpt-6-astra',
                'name' => 'GPT-6 Astra',
                'description' => 'Our most capable model for the most demanding work.',
                'input_per_million' => 10.0,
                'output_per_million' => 50.0,
                'currency' => 'USD',
                'max_output_tokens' => 128_000,
                'context_window_tokens' => 1_050_000,
                'knowledge_cutoff' => '2026-04-30',
                'reasoning' => ['low','medium','high','xhigh','max'],
                'version' => self::VERSION,
                'source' => 'BUILTIN_MODEL_CATALOG',
            ],
            'openai.gpt-6.1-sol' => [
                'provider' => 'openai',
                'model' => 'gpt-6.1-sol',
                'name' => 'GPT-6.1 Sol',
                'description' => 'Near-Astra performance for complex work at a lower cost.',
                'input_per_million' => 2.0,
                'output_per_million' => 10.0,
                'currency' => 'USD',
                'max_output_tokens' => 128_000,
                'context_window_tokens' => 1_050_000,
                'knowledge_cutoff' => '2026-04-30',
                'reasoning' => ['low','medium','high','xhigh','max'],
                'version' => self::VERSION,
                'source' => 'BUILTIN_MODEL_CATALOG',
            ],
            'openai.gpt-6-luna' => [
                'provider' => 'openai',
                'model' => 'gpt-6-luna',
                'name' => 'GPT-6 Luna',
                'description' => 'Our most efficient model for focused, high-volume tasks.',
                'input_per_million' => 0.1,
                'output_per_million' => 0.5,
                'currency' => 'USD',
                'max_output_tokens' => 128_000,
                'context_window_tokens' => 1_050_000,
                'knowledge_cutoff' => '2026-05-18',
                'reasoning' => ['none','low','medium','high','xhigh','max'],
                'version' => self::VERSION,
                'source' => 'BUILTIN_MODEL_CATALOG',
            ],
        ];
    }

    /** @return array<string,array<string,mixed>> */
    public static function pricingCatalog(): array
    {
        return array_map(static fn (array $model): array => [
            'input_per_million' => $model['input_per_million'],
            'output_per_million' => $model['output_per_million'],
            'currency' => $model['currency'],
            'version' => $model['version'],
            'source' => $model['source'],
        ], self::models());
    }
}
