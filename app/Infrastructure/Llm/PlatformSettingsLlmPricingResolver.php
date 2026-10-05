<?php
declare(strict_types=1);

namespace Infrastructure\Llm;

use Kernel\Llm\LlmPriceEstimate;
use Kernel\Llm\LlmPricingResolverInterface;
use Platform\Settings\Contract\PlatformSettingsReaderInterface;

final readonly class PlatformSettingsLlmPricingResolver implements LlmPricingResolverInterface
{
    public function __construct(private PlatformSettingsReaderInterface $settings) {}

    public function estimate(
        ?string $organizationId,
        string $provider,
        string $model,
        ?int $inputTokens,
        ?int $outputTokens,
        ?int $cachedInputTokens = null,
        ?int $reasoningTokens = null,
    ): ?LlmPriceEstimate {
        if ($organizationId === null || ($inputTokens === null && $outputTokens === null)) return null;

        $providerKey = $this->key($provider);
        $modelKey = $this->key($model);
        $catalog = $this->settings->namespace($organizationId, 'llm_pricing');
        $price = null;
        foreach ([$providerKey.'.'.$modelKey, $providerKey.'.default', 'default'] as $key) {
            if (is_array($catalog[$key] ?? null)) {
                $price = $catalog[$key];
                break;
            }
        }
        if (!is_array($price)) return null;

        $inputRate = $this->rate($price['input_per_million'] ?? null);
        $outputRate = $this->rate($price['output_per_million'] ?? null);
        if (($inputTokens !== null && $inputRate === null) || ($outputTokens !== null && $outputRate === null)) return null;

        $cached = max(0, min((int) ($cachedInputTokens ?? 0), (int) ($inputTokens ?? 0)));
        $uncachedInput = max(0, (int) ($inputTokens ?? 0) - $cached);
        $cachedRate = $this->rate($price['cached_input_per_million'] ?? null) ?? $inputRate;

        $amount = 0.0;
        if ($inputRate !== null) $amount += ($uncachedInput / 1_000_000) * $inputRate;
        if ($cachedRate !== null) $amount += ($cached / 1_000_000) * $cachedRate;
        if ($outputRate !== null) $amount += (max(0, (int) ($outputTokens ?? 0)) / 1_000_000) * $outputRate;

        return new LlmPriceEstimate(
            amount: round($amount, 8),
            currency: strtoupper(trim((string) ($price['currency'] ?? 'USD'))) ?: 'USD',
            source: 'CALCULATED_SETTINGS',
            pricingVersion: isset($price['version']) && is_scalar($price['version']) ? (string) $price['version'] : null,
        );
    }

    private function rate(mixed $value): ?float
    {
        if (!is_numeric($value)) return null;
        $rate = (float) $value;
        return $rate >= 0 ? $rate : null;
    }

    private function key(string $value): string
    {
        $key = strtolower(trim($value));
        $key = preg_replace('/[^a-z0-9_.-]+/', '_', $key) ?: 'unknown';
        return trim($key, '._-') ?: 'unknown';
    }
}
