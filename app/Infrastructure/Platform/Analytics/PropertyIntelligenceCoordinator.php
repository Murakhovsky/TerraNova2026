<?php
declare(strict_types=1);

namespace Infrastructure\Platform\Analytics;

use Domains\Property\Application\Contract\PropertyAnalyticsReadModelInterface;
use Domains\Property\Application\DTO\PropertyIntelligenceContext;
use Domains\Property\Application\Service\PropertyComparableSelector;
use Domains\Property\Application\Service\PropertyIntelligenceService;
use Domains\Property\Contract\PropertyReferencePort;
use RuntimeException;

final readonly class PropertyIntelligenceCoordinator
{
    public function __construct(
        private PropertyReferencePort $references,
        private PropertyAnalyticsReadModelInterface $analytics,
        private PropertyMarketAnalyticsService $market,
        private PropertyComparableSelector $comparables,
        private PropertyIntelligenceService $intelligence,
        private string $organizationId,
    ) {}

    /** @return array<string,mixed> */
    public function generate(string|int $reference, ?string $correlationId = null): array
    {
        $presentation = $this->references->getPropertyPresentation($this->organizationId, $reference);
        if ($presentation === null) throw new RuntimeException('Property reference was not found.');
        $property = is_array($presentation['property'] ?? null) ? $presentation['property'] : [];
        $assetId = trim((string) ($property['asset_id'] ?? ''));
        if ($assetId === '') throw new RuntimeException('Property reference has no canonical asset id.');

        $segments = $this->analytics->inventorySegments($this->organizationId);
        $target = null;
        foreach ($segments as $segment) {
            if ((string) ($segment['asset_id'] ?? '') === $assetId) { $target = $segment; break; }
        }
        if ($target === null) throw new RuntimeException('Property has no analyzable inventory segment.');

        $comparables = $this->comparables->select($target, $segments, 10);
        $marketReport = $this->market->report(90);
        $marketSignal = null;
        foreach ((array) ($marketReport['demand_supply'] ?? []) as $segment) {
            if ($this->sameSegment($target, $segment)) { $marketSignal = $segment; break; }
        }

        $context = new PropertyIntelligenceContext(
            $this->organizationId,
            $assetId,
            isset($target['inventory_id']) ? (string) $target['inventory_id'] : null,
            [
                'property' => $property,
                'inventory' => $presentation['inventory'] ?? null,
                'listing' => $presentation['listing'] ?? null,
                'analytics' => [
                    'area_total' => $target['area_total'] ?? null,
                    'price_per_sqm' => $target['price_per_sqm'] ?? null,
                    'inventory_age_days' => $target['inventory_age_days'] ?? null,
                    'days_on_market' => $target['days_on_market'] ?? null,
                ],
            ],
            $comparables,
            [
                'segment' => $marketSignal,
                'demand_basis' => $marketReport['demand_basis'] ?? null,
                'demand_coverage' => $marketReport['demand_coverage'] ?? null,
            ],
            '0.9.0',
            $correlationId,
        );

        return $this->intelligence->generate($context);
    }

    /** @param array<string,mixed> $target @param array<string,mixed> $segment */
    private function sameSegment(array $target, array $segment): bool
    {
        return (string) ($target['type_code'] ?? '') === (string) ($segment['type_code'] ?? '')
            && (string) ($target['location_key'] ?? '') === (string) ($segment['location_key'] ?? '')
            && (string) ($target['area_bucket'] ?? '') === (string) ($segment['area_bucket'] ?? '')
            && (float) ($target['rooms'] ?? -1) === (float) ($segment['rooms'] ?? -2);
    }
}
