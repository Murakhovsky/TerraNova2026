<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);
require $root . '/vendor/autoload.php';

use Domains\Property\Application\Contract\PropertyIntelligenceProviderInterface;
use Domains\Property\Application\Contract\PropertyIntelligenceRepositoryInterface;
use Domains\Property\Application\DTO\PropertyIntelligenceContext;
use Domains\Property\Application\Service\PropertyComparableSelector;
use Domains\Property\Application\Service\PropertyIntelligenceService;

$repository = new class implements PropertyIntelligenceRepositoryInterface {
    public array $saved = [];
    public function save(string $organizationId, array $snapshot, array $comparables): void { $this->saved = [$organizationId, $snapshot, $comparables]; }
    public function latest(string $organizationId, string $assetId): ?array { return $this->saved[1] ?? null; }
    public function history(string $organizationId, string $assetId, int $limit = 20): array { return isset($this->saved[1]) ? [$this->saved[1]] : []; }
};
$provider = new class implements PropertyIntelligenceProviderInterface {
    public function infer(PropertyIntelligenceContext $context): array
    {
        return [
            'estimated_market_value_low' => 108000,
            'estimated_market_value_high' => 112000,
            'value_currency' => 'USD',
            'liquidity_score' => 84,
            'demand_score' => 91,
            'market_position' => 'UNDERPRICED',
            'price_anomaly_percent' => -4.5,
            'inventory_risk' => 'LOW',
            'expected_dom_min' => 31,
            'expected_dom_max' => 45,
            'recommended_asking_price' => 109000,
            'confidence' => 0.82,
            'explanation' => 'Strong observed demand and seven similar units.',
            '_meta' => ['provider' => 'test', 'model' => 'fixture', 'method' => 'structured_llm', 'input_tokens' => 100, 'output_tokens' => 50],
        ];
    }
};

$comparables = [];
for ($i = 1; $i <= 7; $i++) {
    $comparables[] = ['asset_id' => 'CMP-' . $i, 'inventory_id' => 'INV-' . $i, 'similarity_score' => 80 + $i, 'price_amount' => 108000 + $i * 500, 'price_currency' => 'USD', 'area_total' => 63 + $i / 10, 'price_per_sqm' => 1700 + $i, 'reasons' => ['same_type','same_location']];
}
$context = new PropertyIntelligenceContext(
    'org-a', 'APT-52', 'INV-52',
    ['area' => 64.2, 'asking_price' => 105000, 'currency' => 'USD'],
    $comparables,
    ['segment' => ['inventory' => 4, 'active_demand' => 17, 'demand_supply_ratio' => 4.25], 'demand_basis' => 'explicit_property_matches'],
    '0.9.0', 'corr-52',
);

$snapshot = (new PropertyIntelligenceService($provider, $repository))->generate($context);
if (($snapshot['estimated_market_value_low'] ?? null) !== 108000.0 || ($snapshot['estimated_market_value_high'] ?? null) !== 112000.0) throw new RuntimeException('Valuation range drifted.');
if (($snapshot['liquidity_score'] ?? null) !== 84.0 || ($snapshot['market_position'] ?? null) !== 'UNDERPRICED') throw new RuntimeException('Derived intelligence fields drifted.');
if (($snapshot['confidence'] ?? null) !== 0.82 || strlen((string) ($snapshot['evidence_hash'] ?? '')) !== 64) throw new RuntimeException('Inference confidence/evidence traceability missing.');
if (array_key_exists('estimated_market_value_low', $snapshot['facts'])) throw new RuntimeException('Inference leaked into canonical facts.');
if (($repository->saved[0] ?? null) !== 'org-a' || count($repository->saved[2] ?? []) !== 7) throw new RuntimeException('Intelligence snapshot/comparables were not persisted through the contract.');

$selector = new PropertyComparableSelector();
$target = ['asset_id'=>'A','transaction_type'=>'sale','type_code'=>'apartment','location_key'=>'briukhovychi','development_asset_id'=>'D','rooms'=>2,'area_total'=>64.2,'price_currency'=>'USD'];
$candidates = [
    ['asset_id'=>'B','inventory_id'=>'I-B','transaction_type'=>'sale','type_code'=>'apartment','location_key'=>'briukhovychi','development_asset_id'=>'D','rooms'=>2,'area_total'=>65.0,'price_amount'=>109000,'price_currency'=>'USD','price_per_sqm'=>1676.92,'status'=>'available'],
    ['asset_id'=>'C','inventory_id'=>'I-C','transaction_type'=>'sale','type_code'=>'house','location_key'=>'other','development_asset_id'=>null,'rooms'=>5,'area_total'=>180.0,'price_amount'=>250000,'price_currency'=>'USD','price_per_sqm'=>1388.89,'status'=>'available'],
];
$selected = $selector->select($target, $candidates);
if (count($selected) !== 1 || ($selected[0]['asset_id'] ?? null) !== 'B' || ($selected[0]['similarity_score'] ?? 0) < 90) throw new RuntimeException('Deterministic comparable selection failed.');

echo "Property V0.9 intelligence model: OK\n";
