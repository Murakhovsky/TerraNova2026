<?php
declare(strict_types=1);

namespace Infrastructure\Llm;

use Kernel\Llm\LlmPriceEstimate;
use Kernel\Llm\LlmPricingResolverInterface;
use Platform\Settings\Contract\PlatformSettingsReaderInterface;

final readonly class PlatformSettingsLlmPricingResolver implements LlmPricingResolverInterface
{
    public function __construct(
        private PlatformSettingsReaderInterface $settings,
        private string $fallbackCatalogJson = '',
    ) {}

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
        $catalog = OpenAiModelCatalog::pricingCatalog();
        foreach ($this->fallbackCatalog() as $key => $value) {
            if (is_array($value)) {
                $value['source'] = 'ENV_FALLBACK';
                $catalog[(string) $key] = $value;
            }
        }
        foreach ($this->settings->namespace($organizationId, 'llm_pricing') as $key => $value) {
            if (is_array($value)) {
                $value['source'] = 'PLATFORM_SETTINGS';
            }
            $catalog[(string) $key] = $value;
        }
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
        $configuredCachedRate = $this->rate($price['cached_input_per_million'] ?? null);
        $cachedRate = $configuredCachedRate ?? $inputRate;
        $standardInputRateUsedForCache = $cached > 0 && $configuredCachedRate === null;

        $amount = 0.0;
        if ($inputRate !== null) $amount += ($uncachedInput / 1_000_000) * $inputRate;
        if ($cachedRate !== null) $amount += ($cached / 1_000_000) * $cachedRate;
        if ($outputRate !== null) $amount += (max(0, (int) ($outputTokens ?? 0)) / 1_000_000) * $outputRate;

        $source = 'CALCULATED_'.strtoupper(trim((string) ($price['source'] ?? 'CATALOG')));
        if ($standardInputRateUsedForCache) {
            $source .= '_STANDARD_INPUT_RATE_FOR_CACHE';
        }

        return new LlmPriceEstimate(
            amount: round($amount, 8),
            currency: strtoupper(trim((string) ($price['currency'] ?? 'USD'))) ?: 'USD',
            source: $source,
            pricingVersion: isset($price['version']) && is_scalar($price['version']) ? (string) $price['version'] : null,
        );
    }

    /** @return array<string,mixed> */
    private function fallbackCatalog(): array
    {
        $raw = trim($this->fallbackCatalogJson);
        if ($raw === '') return [];
        try {
            $decoded = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
            return is_array($decoded) ? $decoded : [];
        } catch (\Throwable) {
            return [];
        }
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
