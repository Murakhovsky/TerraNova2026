<?php
declare(strict_types=1);

require dirname(__DIR__, 2).'/vendor/autoload.php';

spl_autoload_register(static function (string $class): void {
    if (!str_starts_with($class, 'App\\Engineering\\')) return;
    $path = dirname(__DIR__, 2).'/symfony/src/'.str_replace('\\', '/', substr($class, 4)).'.php';
    if (is_file($path)) require $path;
});

use App\Engineering\Domain\DomainDevelopment\DomainDependencyGraph;
use App\Engineering\Domain\DomainDevelopment\DomainFeatureStatus;
use App\Engineering\Domain\DomainDevelopment\DomainPathReservationPolicy;
use App\Engineering\Domain\DomainDevelopment\DomainReleaseReadinessEvaluator;

$features = [
    ['id' => 'foundation', 'feature_key' => 'foundation', 'status' => DomainFeatureStatus::COMPLETED->value, 'required' => true, 'architecture_version' => 2, 'owned_paths' => ['symfony/src/CapitalMarkets/Shared/'], 'shared_paths' => []],
    ['id' => 'instrument', 'feature_key' => 'instrument', 'status' => DomainFeatureStatus::NOT_STARTED->value, 'required' => true, 'architecture_version' => 2, 'owned_paths' => ['symfony/src/CapitalMarkets/Instrument/'], 'shared_paths' => []],
    ['id' => 'venue', 'feature_key' => 'venue', 'status' => DomainFeatureStatus::NOT_STARTED->value, 'required' => true, 'architecture_version' => 2, 'owned_paths' => ['symfony/src/CapitalMarkets/Venue/'], 'shared_paths' => []],
];
$dependencies = [
    ['feature_id' => 'instrument', 'depends_on_feature_id' => 'foundation', 'dependency_type' => 'REQUIRES'],
    ['feature_id' => 'venue', 'depends_on_feature_id' => 'instrument', 'dependency_type' => 'REQUIRES'],
];

$graph = new DomainDependencyGraph($features, $dependencies);
if ($graph->topologicalOrder() !== ['foundation', 'instrument', 'venue']) {
    throw new RuntimeException('Domain dependency graph topological order is invalid.');
}
if ($graph->readyFeatureIds() !== ['instrument']) {
    throw new RuntimeException('Domain dependency graph did not unlock the expected feature.');
}

$cycleRejected = false;
try {
    new DomainDependencyGraph($features, array_merge($dependencies, [
        ['feature_id' => 'foundation', 'depends_on_feature_id' => 'venue', 'dependency_type' => 'REQUIRES'],
    ]));
} catch (LogicException) {
    $cycleRejected = true;
}
if (!$cycleRejected) throw new RuntimeException('Domain dependency graph accepted a cycle.');

$paths = new DomainPathReservationPolicy();
if (!$paths->conflicts(
    ['owned_paths' => ['symfony/src/CapitalMarkets/Instrument/'], 'shared_paths' => []],
    [['owned_paths' => ['symfony/src/CapitalMarkets/'], 'shared_paths' => []]],
)) {
    throw new RuntimeException('Path reservation did not detect nested ownership collision.');
}
if ($paths->conflicts(
    ['owned_paths' => ['symfony/src/CapitalMarkets/Instrument/'], 'shared_paths' => []],
    [['owned_paths' => ['symfony/src/CRM/'], 'shared_paths' => []]],
)) {
    throw new RuntimeException('Path reservation produced false positive.');
}

$release = new DomainReleaseReadinessEvaluator();
$ready = $release->evaluate(
    ['architecture_version' => 2, 'qa_status' => 'PASS'],
    array_map(static fn (array $feature): array => array_merge($feature, ['status' => 'COMPLETED']), $features),
    [['contract_key' => 'VenueAdapter', 'status' => 'ACTIVE']],
);
if (!$ready['ready']) throw new RuntimeException('Release gate rejected valid Domain release.');

$blocked = $release->evaluate(
    ['architecture_version' => 2, 'qa_status' => 'FAIL'],
    $features,
    [['contract_key' => 'VenueAdapter', 'status' => 'BROKEN']],
);
if ($blocked['ready'] || count($blocked['blockers']) < 2) {
    throw new RuntimeException('Release gate allowed invalid Domain release.');
}

echo "Engineering Domain Runtime unit checks passed\n";
