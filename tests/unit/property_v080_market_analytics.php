<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);
require $root . '/vendor/autoload.php';

use Domains\Property\Application\Contract\PropertyAnalyticsReadModelInterface;
use Domains\Sales\Application\Contract\SalesDemandReadModelInterface;
use Infrastructure\Platform\Analytics\PropertyMarketAnalyticsService;

$property = new class implements PropertyAnalyticsReadModelInterface {
    public function summary(string $organizationId, int $days = 30): array { return ['total_assets' => 4]; }
    public function stock(string $organizationId): array { return []; }
    public function changes(string $organizationId, int $days = 30): array { return ['price_changes' => 0, 'availability_changes' => 0]; }
    public function inventorySegments(string $organizationId): array
    {
        $rows = [];
        for ($id = 1; $id <= 4; $id++) {
            $rows[] = [
                'legacy_property_id' => $id,
                'asset_id' => 'APT-' . $id,
                'status' => 'available',
                'type_code' => 'apartment',
                'location_key' => 'ua/lviv/briukhovychi',
                'location_name' => 'Брюховичі',
                'area_bucket' => '55-70',
                'rooms' => 2.0,
            ];
        }
        return $rows;
    }
};

$sales = new class implements SalesDemandReadModelInterface {
    public function activePropertyInterests(string $organizationId): array
    {
        $rows = [];
        for ($case = 1; $case <= 17; $case++) {
            $rows[] = [
                'client_case_id' => $case,
                'property_id' => (($case - 1) % 4) + 1,
                'match_status' => 'interested',
                'score' => null,
            ];
        }
        return $rows;
    }
    public function coverage(string $organizationId): array
    {
        return ['active_cases' => 20, 'cases_with_property_matches' => 17];
    }
};

$service = new PropertyMarketAnalyticsService($property, $sales, 'org-a');
$report = $service->report(30);
$segment = $report['demand_supply'][0] ?? null;
if (!is_array($segment)) throw new RuntimeException('Demand/supply segment was not produced.');
if (($segment['inventory'] ?? null) !== 4) throw new RuntimeException('Expected 4 available inventory items.');
if (($segment['active_demand'] ?? null) !== 17) throw new RuntimeException('Expected 17 unique active ClientCases.');
if (($segment['demand_supply_ratio'] ?? null) !== 4.25) throw new RuntimeException('Expected demand/supply ratio 4.25.');
if (($segment['area_bucket'] ?? null) !== '55-70' || ($segment['rooms'] ?? null) !== 2.0) {
    throw new RuntimeException('Canonical demand/supply segment dimensions drifted.');
}
if (($report['demand_coverage']['coverage_percent'] ?? null) !== 85.0) throw new RuntimeException('Demand coverage must remain explicit.');
if (($report['demand_basis'] ?? null) !== 'explicit_property_matches') throw new RuntimeException('Observed demand basis must be disclosed.');

echo "Property V0.8 market analytics: OK\n";
