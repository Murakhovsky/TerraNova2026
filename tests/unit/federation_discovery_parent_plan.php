<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Kernel\Module\CanonicalCapabilityCatalog;
use Kernel\Module\ModuleCatalog;
use Kernel\Module\ModuleDiscovery;
use Platform\Orchestration\Goal\FederationDiscoveryPlanFactory;
use Platform\Orchestration\Goal\FederationGolden50GoalProfile;
use Platform\Orchestration\Goal\GoalPlanValidator;
use Platform\Orchestration\Goal\GoalSpecification;

$root = dirname(__DIR__, 2);
$catalog = new CanonicalCapabilityCatalog(new ModuleCatalog(
    (new ModuleDiscovery($root . '/app/Domains'))->discover()
));
$factory = new FederationDiscoveryPlanFactory();
$allowed = FederationGolden50GoalProfile::capabilities('federation.verified_proposals', 50);
$goal = new GoalSpecification('goal-50', 'tenant-50', '42', 'Prepare fifty verified proposals',
    [['id' => 'federation.verified_proposals', 'operator' => 'at_least', 'expected' => 50]],
    $allowed,
);
$a = $factory->build($goal, 'GMU-PROD', 1, 200);
$b = $factory->build($goal, 'GMU-PROD', 2, 200);
if ($a !== $factory->build($goal, 'GMU-PROD', 1, 200)
    || $a['plan_id'] === $b['plan_id']
    || count($a['steps']) !== 1
    || $a['steps'][0]['id'] !== 'discovery'
    || $a['steps'][0]['depends_on'] !== []
    || $a['steps'][0]['input']['target_id'] !== 'GMU-PROD'
    || $a['steps'][0]['input']['parameters']['limit'] !== 200) {
    throw new RuntimeException('Deterministic explicit Discovery Plan shape drifted.');
}
$check = (new GoalPlanValidator($catalog))->validate($goal, $a['steps'], $allowed);
if (!$check['valid'] || count($check['steps']) !== 1
    || $check['steps'][0]['side_effect_level'] !== 'external'
    || $check['steps'][0]['approval_policy'] !== 'required') {
    throw new RuntimeException('Parent Discovery Plan bypassed canonical Action validation: '
        . implode(';', $check['errors']));
}
$bad = $a['steps'];
$bad[0]['input']['parameters']['limit'] = 201;
if ((new GoalPlanValidator($catalog))->validate($goal, $bad, $allowed)['valid']) {
    throw new RuntimeException('Oversized market scan passed canonical Action input.');
}
foreach ([[0, 100], [11, 100], [1, 0], [1, 201]] as [$slot, $limit]) {
    try {
        $factory->build($goal, 'GMU-PROD', $slot, $limit);
        throw new RuntimeException('Unbounded discovery slot or scan limit was accepted.');
    } catch (DomainException) {}
}
$revenue = new GoalSpecification('goal-50', 'tenant-50', '42', 'Win deals',
    [['id' => 'sales.won_deals', 'operator' => 'at_least', 'expected' => 50]],
    $allowed,
);
try {
    $factory->build($revenue, 'GMU-PROD', 1, 200);
    throw new RuntimeException('Discovery proposal accepted a different Goal metric.');
} catch (DomainException) {}
echo "Federation Golden 50 parent Discovery Plan: deterministic, bounded, schema-approved, no execution PASS.\n";
