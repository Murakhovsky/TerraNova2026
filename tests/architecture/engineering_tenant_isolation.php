<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);
$migration = (string) file_get_contents($root.'/symfony/migrations/Version20261003133000.php');
$feature = (string) file_get_contents($root.'/symfony/src/Persistence/Doctrine/Entity/Engineering/EngineeringFeatureRecord.php');
$orchestrator = (string) file_get_contents($root.'/symfony/src/Engineering/Application/Service/EngineeringOrchestrator.php');
$controller = (string) file_get_contents($root.'/symfony/src/Http/Api/V1/Controller/EngineeringController.php');
$metrics = (string) file_get_contents($root.'/symfony/src/Engineering/Infrastructure/Metrics/DoctrineEngineeringMetricsProvider.php');

foreach (['organization_id VARCHAR(64) NOT NULL','idx_cos_eng_feature_org'] as $needle) {
    if (!str_contains($migration, $needle)) throw new RuntimeException('Engineering tenant schema missing '.$needle);
}
foreach (['organizationId()', 'organization_id'] as $needle) {
    if (!str_contains($feature.$controller, $needle)) throw new RuntimeException('Engineering tenant ownership missing '.$needle);
}
if (!str_contains($orchestrator, "does not belong to the current organization")) throw new RuntimeException('Engineering start tenant guard missing.');
if (!str_contains($metrics, 'organization_id=:organization_id')) throw new RuntimeException('Engineering metrics are not tenant scoped.');

echo "Engineering tenant isolation passed.\n";
