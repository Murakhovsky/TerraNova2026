<?php
declare(strict_types=1);

namespace Domains\Property\Application\Service;

use Domains\Property\Application\Contract\PropertyIntelligenceProviderInterface;
use Domains\Property\Application\Contract\PropertyIntelligenceRepositoryInterface;
use Domains\Property\Application\DTO\PropertyIntelligenceContext;
use Domains\Property\Model\PropertyMarketPosition;

final readonly class PropertyIntelligenceService
{
    public function __construct(
        private PropertyIntelligenceProviderInterface $provider,
        private PropertyIntelligenceRepositoryInterface $repository,
    ) {}

    /** @return array<string,mixed> */
    public function generate(PropertyIntelligenceContext $context): array
    {
        $raw = $this->provider->infer($context);
        $meta = is_array($raw['_meta'] ?? null) ? $raw['_meta'] : [];
        unset($raw['_meta']);

        $low = $this->nullableFloat($raw['estimated_market_value_low'] ?? null);
        $high = $this->nullableFloat($raw['estimated_market_value_high'] ?? null);
        if ($low !== null && $high !== null && $high < $low) [$low, $high] = [$high, $low];

        $evidence = $context->evidence();
        $inferenceId = 'INT-' . strtoupper(substr(bin2hex(random_bytes(16)), 0, 24));
        $snapshot = [
            'inference_id' => $inferenceId,
            'asset_id' => $context->assetId,
            'inventory_id' => $context->inventoryId,
            'provider' => trim((string) ($meta['provider'] ?? 'unknown')) ?: 'unknown',
            'model' => $this->nullableString($meta['model'] ?? null, 160),
            'method' => trim((string) ($meta['method'] ?? 'derived')) ?: 'derived',
            'methodology_version' => $context->methodologyVersion,
            'evidence_hash' => hash('sha256', json_encode($this->canonical($evidence), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)),
            'facts' => $context->facts,
            'market_signals' => $context->marketSignals,
            'output' => $raw,
            'estimated_market_value_low' => $low,
            'estimated_market_value_high' => $high,
            'value_currency' => $this->currency($raw['value_currency'] ?? null),
            'liquidity_score' => $this->score($raw['liquidity_score'] ?? null),
            'demand_score' => $this->score($raw['demand_score'] ?? null),
            'market_position' => $this->marketPosition((string) ($raw['market_position'] ?? PropertyMarketPosition::UNKNOWN)),
            'price_anomaly_percent' => $this->nullableFloat($raw['price_anomaly_percent'] ?? null),
            'inventory_risk' => $this->risk((string) ($raw['inventory_risk'] ?? 'UNKNOWN')),
            'expected_dom_min' => $this->nonNegativeInt($raw['expected_dom_min'] ?? null),
            'expected_dom_max' => $this->nonNegativeInt($raw['expected_dom_max'] ?? null),
            'recommended_asking_price' => $this->nullableFloat($raw['recommended_asking_price'] ?? null),
            'confidence' => $this->confidence($raw['confidence'] ?? 0),
            'explanation' => $this->nullableString($raw['explanation'] ?? null, 4000),
            'correlation_id' => $context->correlationId,
            'input_tokens' => $this->nonNegativeInt($meta['input_tokens'] ?? null),
            'output_tokens' => $this->nonNegativeInt($meta['output_tokens'] ?? null),
            'cost_amount' => $this->nullableFloat($meta['cost_amount'] ?? null),
            'cost_currency' => $this->currency($meta['cost_currency'] ?? null),
            'generated_at' => date('Y-m-d H:i:s'),
            'valid_until' => null,
        ];

        if ($snapshot['expected_dom_min'] !== null && $snapshot['expected_dom_max'] !== null
            && $snapshot['expected_dom_max'] < $snapshot['expected_dom_min']) {
            [$snapshot['expected_dom_min'], $snapshot['expected_dom_max']] = [$snapshot['expected_dom_max'], $snapshot['expected_dom_min']];
        }

        $this->repository->save($context->organizationId, $snapshot, $context->comparables);
        return $snapshot;
    }

    /** @return array<string,mixed>|null */
    public function latest(string $organizationId, string $assetId): ?array
    {
        return $this->repository->latest($organizationId, $assetId);
    }

    private function score(mixed $value): ?float
    {
        $value = $this->nullableFloat($value);
        return $value === null ? null : round(max(0, min(100, $value)), 2);
    }

    private function confidence(mixed $value): float
    {
        return round(max(0, min(1, is_numeric($value) ? (float) $value : 0.0)), 4);
    }

    private function marketPosition(string $value): string
    {
        $value = strtoupper(trim($value));
        return in_array($value, PropertyMarketPosition::values(), true) ? $value : PropertyMarketPosition::UNKNOWN;
    }

    private function risk(string $value): string
    {
        $value = strtoupper(trim($value));
        return in_array($value, ['LOW','MEDIUM','HIGH','UNKNOWN'], true) ? $value : 'UNKNOWN';
    }

    private function currency(mixed $value): ?string
    {
        $value = strtoupper(trim((string) ($value ?? '')));
        return preg_match('/^[A-Z]{3}$/', $value) ? $value : null;
    }

    private function nullableFloat(mixed $value): ?float
    {
        return is_numeric($value) ? (float) $value : null;
    }

    private function nonNegativeInt(mixed $value): ?int
    {
        return is_numeric($value) ? max(0, (int) $value) : null;
    }

    private function nullableString(mixed $value, int $limit): ?string
    {
        $value = trim((string) ($value ?? ''));
        return $value === '' ? null : mb_substr($value, 0, $limit);
    }

    private function canonical(mixed $value): mixed
    {
        if (!is_array($value)) return $value;
        if (!array_is_list($value)) ksort($value);
        foreach ($value as $key => $item) $value[$key] = $this->canonical($item);
        return $value;
    }
}
