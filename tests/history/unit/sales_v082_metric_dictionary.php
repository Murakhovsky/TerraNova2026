<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);
require $root . '/vendor/autoload.php';

use Domains\Sales\Application\Service\SalesMetricDictionary;

$definitions = (new SalesMetricDictionary())->definitions();
foreach ([
    'pipeline_value','weighted_pipeline','win_rate_closed','win_rate_created','stage_conversion',
    'stage_duration','sales_cycle','stuck_deals','at_risk_revenue','cohort_funnel','transition_flow',
] as $code) {
    if (!isset($definitions[$code])) throw new RuntimeException('Missing metric definition: ' . $code);
    $row = $definitions[$code]->toArray();
    foreach (['name','business_definition','numerator','denominator','time_basis','filters','currency_behavior','data_quality_requirements'] as $field) {
        if (!array_key_exists($field, $row)) throw new RuntimeException("Metric {$code} misses {$field}.");
    }
}
if ($definitions['win_rate_closed']->name === $definitions['win_rate_created']->name) {
    throw new RuntimeException('Closed and created-cohort win rates must have distinct canonical names.');
}
if (!str_contains(strtolower($definitions['pipeline_value']->currencyBehavior), 'never sum')) {
    throw new RuntimeException('Pipeline value must explicitly prohibit cross-currency totals.');
}
if ($definitions['cohort_funnel']->businessDefinition === $definitions['transition_flow']->businessDefinition) {
    throw new RuntimeException('Cohort funnel and transition flow must remain different questions.');
}

echo "Sales V0.8.2 metric dictionary: OK\n";
