<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);
require $root . '/vendor/autoload.php';

use Infrastructure\Visualization\Architecture\ArchitectureGraphHealthAnalyzer;
use Kernel\Visualization\Graph\Edge;
use Kernel\Visualization\Graph\Graph;
use Kernel\Visualization\Graph\Group;
use Kernel\Visualization\Graph\Node;

$analyzer = new ArchitectureGraphHealthAnalyzer();

$healthy = new Graph(
    [
        new Node('domain:sales', 'domain', 'Sales'),
        new Node('event:lead_created', 'event', 'LeadCreated'),
    ],
    [
        new Edge('edge:owns', 'domain:sales', 'event:lead_created', 'owns'),
    ],
);
$health = $analyzer->analyze($healthy);
if ($health['status'] !== 'healthy' || $health['total'] !== 0 || $health['issues'] !== []) {
    throw new RuntimeException('Healthy graph was not classified as healthy.');
}

$problematic = new Graph(
    [
        new Node('node:a', 'service', 'A'),
        new Node('node:b', 'service', 'B'),
        new Node('node:c', 'service', 'C'),
    ],
    [
        new Edge('edge:self', 'node:a', 'node:a', 'depends_on'),
        new Edge('edge:one', 'node:a', 'node:b', 'depends_on'),
        new Edge('edge:two', 'node:a', 'node:b', 'depends_on'),
    ],
);
$problemHealth = $analyzer->analyze($problematic);
$codes = array_column($problemHealth['issues'], 'code');
foreach (['self_loop', 'duplicate_semantic_edge', 'isolated_node'] as $code) {
    if (!in_array($code, $codes, true)) {
        throw new RuntimeException('Missing graph health issue: ' . $code);
    }
}
if ($problemHealth['status'] !== 'error' || $problemHealth['errors'] < 1 || $problemHealth['warnings'] < 2) {
    throw new RuntimeException('Problematic graph severity aggregation failed.');
}

$nodeCycle = new Graph([
    new Node('node:x', 'service', 'X', 'node:y'),
    new Node('node:y', 'service', 'Y', 'node:x'),
]);
$cycleHealth = $analyzer->analyze($nodeCycle);
if (!in_array('node_parent_cycle', array_column($cycleHealth['issues'], 'code'), true)) {
    throw new RuntimeException('Node parent cycle was not detected.');
}

$groupCycle = new Graph(
    [new Node('node:grouped', 'service', 'Grouped', 'group:a')],
    [],
    [
        new Group('group:a', 'A', 'group:b'),
        new Group('group:b', 'B', 'group:a'),
        new Group('group:empty', 'Empty'),
    ],
);
$groupHealth = $analyzer->analyze($groupCycle);
$groupCodes = array_column($groupHealth['issues'], 'code');
if (!in_array('group_parent_cycle', $groupCodes, true) || !in_array('empty_group', $groupCodes, true)) {
    throw new RuntimeException('Group health diagnostics failed.');
}

$emptyHealth = $analyzer->analyze(new Graph());
if ($emptyHealth['status'] !== 'error' || !in_array('empty_graph', array_column($emptyHealth['issues'], 'code'), true)) {
    throw new RuntimeException('Empty graph must be an error.');
}

echo "Visualization V0.5.5 graph health analyzer invariants passed.\n";
