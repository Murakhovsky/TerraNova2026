<?php
declare(strict_types=1);

namespace Infrastructure\Platform\Analytics;

use Domains\Property\Application\Contract\PropertyAnalyticsReadModelInterface;
use Domains\Sales\Application\Contract\SalesDemandReadModelInterface;

final readonly class PropertyMarketAnalyticsService
{
    public function __construct(
        private PropertyAnalyticsReadModelInterface $property,
        private SalesDemandReadModelInterface $sales,
        private string $organizationId,
    ) {
    }

    /** @return array<string,mixed> */
    public function report(int $days = 30): array
    {
        return [
            'summary' => $this->property->summary($this->organizationId, $days),
            'stock' => $this->property->stock($this->organizationId),
            'demand_supply' => $this->demandSupply(),
            'demand_coverage' => $this->demandCoverage(),
            'demand_basis' => 'explicit_property_matches',
        ];
    }

    /** @return list<array<string,mixed>> */
    public function demandSupply(): array
    {
        $inventory = $this->property->inventorySegments($this->organizationId);
        $byLegacyId = [];
        $supplyBySegment = [];
        $segmentMeta = [];

        foreach ($inventory as $row) {
            $legacyId = (int) ($row['legacy_property_id'] ?? 0);
            if ($legacyId > 0) $byLegacyId[$legacyId] = $row;

            $key = $this->segmentKey($row);
            $segmentMeta[$key] ??= $this->segmentMeta($row);
            if (($row['status'] ?? null) === 'available') {
                $supplyBySegment[$key] = ($supplyBySegment[$key] ?? 0) + 1;
            }
        }

        $demandCases = [];
        foreach ($this->sales->activePropertyInterests($this->organizationId) as $interest) {
            $propertyId = (int) ($interest['property_id'] ?? 0);
            if ($propertyId <= 0 || !isset($byLegacyId[$propertyId])) continue;
            $row = $byLegacyId[$propertyId];
            $key = $this->segmentKey($row);
            $segmentMeta[$key] ??= $this->segmentMeta($row);
            $caseId = (int) ($interest['client_case_id'] ?? 0);
            if ($caseId > 0) $demandCases[$key][$caseId] = true;
        }

        $keys = array_values(array_unique(array_merge(array_keys($supplyBySegment), array_keys($demandCases))));
        $result = [];
        foreach ($keys as $key) {
            $supply = (int) ($supplyBySegment[$key] ?? 0);
            $demand = isset($demandCases[$key]) ? count($demandCases[$key]) : 0;
            $meta = $segmentMeta[$key] ?? [];
            $result[] = $meta + [
                'segment_key' => $key,
                'inventory' => $supply,
                'active_demand' => $demand,
                'demand_supply_ratio' => $supply > 0 ? round($demand / $supply, 2) : null,
                'supply_gap' => $demand - $supply,
            ];
        }

        usort($result, static function (array $a, array $b): int {
            $ratioA = $a['demand_supply_ratio'] ?? INF;
            $ratioB = $b['demand_supply_ratio'] ?? INF;
            return $ratioB <=> $ratioA ?: ($b['active_demand'] <=> $a['active_demand']);
        });

        return $result;
    }

    /** @return array<string,mixed> */
    private function demandCoverage(): array
    {
        $coverage = $this->sales->coverage($this->organizationId);
        $active = (int) ($coverage['active_cases'] ?? 0);
        $matched = (int) ($coverage['cases_with_property_matches'] ?? 0);
        return $coverage + [
            'coverage_percent' => $active > 0 ? round($matched * 100 / $active, 1) : 0.0,
        ];
    }

    /** @param array<string,mixed> $row */
    private function segmentKey(array $row): string
    {
        return implode('|', [
            (string) ($row['type_code'] ?? 'unknown'),
            (string) (($row['location_key'] ?? null) ?: ($row['location_name'] ?? 'unknown')),
            (string) ($row['area_bucket'] ?? 'unknown'),
            $this->rooms($row['rooms'] ?? null),
        ]);
    }

    /** @param array<string,mixed> $row @return array<string,mixed> */
    private function segmentMeta(array $row): array
    {
        return [
            'type_code' => (string) ($row['type_code'] ?? 'unknown'),
            'location_key' => $row['location_key'] ?? null,
            'location_name' => $row['location_name'] ?? null,
            'area_bucket' => (string) ($row['area_bucket'] ?? 'unknown'),
            'rooms' => $row['rooms'] !== null ? (float) $row['rooms'] : null,
        ];
    }

    private function rooms(mixed $value): string
    {
        if ($value === null || $value === '') return 'unknown';
        $number = (float) $value;
        return floor($number) === $number ? (string) (int) $number : rtrim(rtrim(number_format($number, 2, '.', ''), '0'), '.');
    }
}
