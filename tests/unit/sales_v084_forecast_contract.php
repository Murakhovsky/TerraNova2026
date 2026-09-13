<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);
$read = static fn (string $path): string => (string) file_get_contents($root . '/' . $path);

$dictionary = $read('app/Domains/Sales/Application/Service/SalesMetricDictionary.php');
foreach (['forecast_value','weighted_forecast','forecast_coverage','risk_explainability'] as $metric) {
    if (!str_contains($dictionary, "'$metric'")) throw new RuntimeException("Metric dictionary missing $metric.");
}
if (!str_contains($dictionary, 'never combine currencies')) throw new RuntimeException('Forecast monetary dictionary must forbid mixed-currency totals.');

$service = $read('app/Domains/Sales/Application/Service/SalesForecastRiskService.php');
foreach (['STUCK','NEXT_CONTACT_OVERDUE','EXPECTED_CLOSE_OVERDUE'] as $reason) {
    if (!str_contains($service, "'$reason'")) throw new RuntimeException("Forecast risk missing $reason.");
}
if (!str_contains($service, "FORMULA_VERSION = 'sales-forecast-risk-v0.8.4'")) throw new RuntimeException('Forecast formula must be explicitly versioned.');
if (!str_contains($service, "historyQuality !== 'ESTIMATED'")) throw new RuntimeException('ESTIMATED stage history must be excluded from exact stage age.');
if (!str_contains($service, "'source' => 'STAGE_DEFAULT'") && !str_contains($service, "\$source = 'STAGE_DEFAULT'")) throw new RuntimeException('Probability fallback source must be explicit.');

$bootstrap = $read('app/Bootstrap/SalesHistoricalIntelligenceServices.php');
if (!str_contains($bootstrap, "'salesForecastRiskReadModel'")) throw new RuntimeException('Forecast read model is not wired.');
if (!str_contains($bootstrap, "'salesForecastRisk'")) throw new RuntimeException('Forecast service is not wired.');

$module = require $root . '/app/Domains/Sales/module.php';
if (($module['version'] ?? null) !== '0.8.4') throw new RuntimeException('Sales module must be V0.8.4.');
if (($module['schema_version'] ?? null) !== '0.8.3') throw new RuntimeException('V0.8.4 is read-only analytics; schema version must not pretend a migration exists.');
$migrations = $module['contributions']['migration_files'] ?? [];
foreach ($migrations as $migration) {
    if (str_contains((string) $migration, 'v084')) throw new RuntimeException('V0.8.4 must not register a fake schema migration.');
}

echo "Sales V0.8.4 forecast contract: OK\n";
