<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);
require $root.'/vendor/autoload.php';

use Infrastructure\Llm\OpenAiModelCatalog;
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

$catalog = new OpenAiModelCatalog();
$resolver = new PlatformSettingsLlmPricingResolver($settings, $catalog);
$estimate = $resolver->estimate('org-1', 'openai', 'gpt-test', 1_000_000, 500_000, 200_000, 100_000);
if ($estimate === null) throw new RuntimeException('Pricing estimate was not created.');
if (abs($estimate->amount - 5.7) > 0.000001) throw new RuntimeException('Cached-token pricing math drifted: '.$estimate->amount);
if ($estimate->currency !== 'USD' || $estimate->source !== 'CALCULATED_PLATFORM_SETTINGS' || $estimate->pricingVersion !== 'test-v1') {
    throw new RuntimeException('Pricing provenance metadata drifted.');
}
if ($resolver->estimate('org-1', 'openai', 'unknown-model', 1000, 1000) !== null) {
    throw new RuntimeException('Unknown model must not fabricate a cost.');
}



$emptySettings = new class implements PlatformSettingsReaderInterface {
    public function value(string $organizationId, string $namespace, string $key, mixed $fallback = null): mixed { return $fallback; }
    public function secret(string $organizationId, string $namespace, string $key, ?string $fallback = null): ?string { return $fallback; }
    public function namespace(string $organizationId, string $namespace): array { return []; }
};

$builtIn = new PlatformSettingsLlmPricingResolver($emptySettings, $catalog);
$cases = [
    'gpt-6-astra' => [10.0, 50.0, 35.0],
    'gpt-6.1-sol' => [2.0, 10.0, 7.0],
    'gpt-6-luna' => [0.1, 0.5, 0.35],
];
foreach ($cases as $model => [$inputRate, $outputRate, $expected]) {
    $price = $builtIn->estimate('org-1', 'openai', $model, 1_000_000, 500_000);
    if ($price === null) throw new RuntimeException('Built-in pricing missing for '.$model.'.');
    if (abs($price->amount - $expected) > 0.000001) {
        throw new RuntimeException(sprintf('%s pricing drifted: expected %.8f, got %.8f.', $model, $expected, $price->amount));
    }
    if ($price->source !== 'CALCULATED_BUILTIN_MODEL_CATALOG' || $price->pricingVersion !== '2026-10-05') {
        throw new RuntimeException($model.' built-in pricing provenance drifted.');
    }
}

$cachedEstimate = $builtIn->estimate('org-1', 'openai', 'gpt-6.1-sol', 1_000_000, 0, 500_000);
if ($cachedEstimate === null || abs($cachedEstimate->amount - 2.0) > 0.000001) {
    throw new RuntimeException('Missing cached-input price must conservatively use the standard input rate.');
}
if (!str_contains($cachedEstimate->source, 'STANDARD_INPUT_RATE_FOR_CACHE')) {
    throw new RuntimeException('Cached-input pricing assumption must be explicit in cost provenance.');
}

echo "LLM tenant pricing accounting passed.\n";
