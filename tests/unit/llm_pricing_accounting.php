<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);
require $root.'/vendor/autoload.php';

use Infrastructure\Llm\PlatformSettingsLlmPricingResolver;
use Platform\Settings\Contract\PlatformSettingsReaderInterface;

$settings = new class implements PlatformSettingsReaderInterface {
    public function value(string $organizationId, string $namespace, string $key, mixed $fallback = null): mixed { return $fallback; }
    public function secret(string $organizationId, string $namespace, string $key, ?string $fallback = null): ?string { return $fallback; }
    public function namespace(string $organizationId, string $namespace): array
    {
        if ($namespace !== 'llm_pricing') return [];
        return [
            'openai.gpt-test' => [
                'input_per_million' => 2.0,
                'cached_input_per_million' => 0.5,
                'output_per_million' => 8.0,
                'currency' => 'USD',
                'version' => 'test-v1',
            ],
        ];
    }
};

$resolver = new PlatformSettingsLlmPricingResolver($settings);
$estimate = $resolver->estimate('org-1', 'openai', 'gpt-test', 1_000_000, 500_000, 200_000, 100_000);
if ($estimate === null) throw new RuntimeException('Pricing estimate was not created.');
if (abs($estimate->amount - 5.7) > 0.000001) throw new RuntimeException('Cached-token pricing math drifted: '.$estimate->amount);
if ($estimate->currency !== 'USD' || $estimate->source !== 'CALCULATED_SETTINGS' || $estimate->pricingVersion !== 'test-v1') {
    throw new RuntimeException('Pricing provenance metadata drifted.');
}
if ($resolver->estimate('org-1', 'openai', 'unknown-model', 1000, 1000) !== null) {
    throw new RuntimeException('Unknown model must not fabricate a cost.');
}

echo "LLM tenant pricing accounting passed.\n";
