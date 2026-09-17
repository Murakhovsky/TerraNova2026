<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);
require $root . '/vendor/autoload.php';

use Infrastructure\Visualization\Architecture\ArchitectureProjectionRegistry;
use Kernel\Visualization\Graph\Edge;
use Kernel\Visualization\Graph\Graph;
use Kernel\Visualization\Graph\GraphFilter;
use Kernel\Visualization\Graph\GraphView;
use Kernel\Visualization\Graph\Node;

$assert = static function (bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
};
$ids = static fn (Graph $graph): array => array_map(static fn (Node $node): string => $node->id, $graph->nodes());
$relations = static fn (Graph $graph): array => array_map(static fn (Edge $edge): string => $edge->relation, $graph->edges());

$nodes = [
    new Node('kernel:cos', 'kernel', 'COS Kernel'),
    new Node('domain:sales', 'domain', 'Sales'),
    new Node('domain:property', 'domain', 'Property'),
    new Node('capability:sales.pipeline', 'capability', 'sales.pipeline'),
    new Node('contract:property-reference', 'contract', 'PropertyReferencePort'),
    new Node('event:deal.changed', 'event', 'deal.changed'),
    new Node('rule:sales:followup', 'rule', 'Follow-up rule'),
    new Node('action:deal.close', 'action', 'deal.close'),
    new Node('policy:sales:deal.close', 'policy', 'Close policy'),
    new Node('agent:sales.agent', 'agent', 'sales.agent'),
    new Node('handler:close', 'handler', 'CloseHandler'),
    new Node('service:salesRoute', 'service', 'salesRoute'),
    new Node('extension_point:web.navigation', 'extension_point', 'web.navigation'),
];
$edges = [
    new Edge('e1', 'kernel:cos', 'domain:sales', 'contains'),
    new Edge('e2', 'kernel:cos', 'domain:property', 'contains'),
    new Edge('e3', 'domain:sales', 'domain:property', 'depends_on'),
    new Edge('e4', 'domain:sales', 'capability:sales.pipeline', 'owns'),
    new Edge('e5', 'domain:sales', 'event:deal.changed', 'owns'),
    new Edge('e6', 'domain:sales', 'rule:sales:followup', 'owns'),
    new Edge('e7', 'event:deal.changed', 'rule:sales:followup', 'triggers'),
    new Edge('e8', 'rule:sales:followup', 'action:deal.close', 'produces'),
    new Edge('e9', 'domain:sales', 'action:deal.close', 'owns'),
    new Edge('e10', 'domain:sales', 'policy:sales:deal.close', 'owns'),
    new Edge('e11', 'policy:sales:deal.close', 'action:deal.close', 'governs'),
    new Edge('e12', 'action:deal.close', 'handler:close', 'handled_by'),
    new Edge('e13', 'domain:sales', 'agent:sales.agent', 'owns'),
    new Edge('e14', 'agent:sales.agent', 'action:deal.close', 'proposes'),
    new Edge('e15', 'domain:sales', 'service:salesRoute', 'contributes'),
    new Edge('e16', 'service:salesRoute', 'extension_point:web.navigation', 'contributes_to'),
    new Edge('e17', 'domain:property', 'contract:property-reference', 'owns'),
    new Edge('e18', 'domain:sales', 'contract:property-reference', 'requires_contract'),
    new Edge('e19', 'domain:property', 'contract:property-reference', 'provides_contract'),
];
$graph = new Graph($nodes, $edges);
$registry = ArchitectureProjectionRegistry::defaults();

$assert($registry->names() === ['system', 'runtime', 'domain', 'dependencies', 'contracts', 'events', 'actions', 'agents', 'integrations', 'code'], 'Canonical projection order changed.');
$descriptions = $registry->descriptions();
$assert(($descriptions['system']['layout'] ?? null) === 'radial', 'System must use a cycle-safe radial layout hint.');
$assert(($descriptions['runtime']['layout'] ?? null) === 'flow', 'Runtime layout hint missing.');
$assert(($descriptions['domain']['layout'] ?? null) === 'radial', 'Domain layout hint missing.');
$assert(($descriptions['domain']['default_depth'] ?? null) === 2, 'Domain default depth missing.');
$assert(($descriptions['dependencies']['layout'] ?? null) === 'force', 'Dependencies must use a network-safe force layout hint.');
$assert(($descriptions['contracts']['layout'] ?? null) === 'flow', 'Contracts layout hint missing.');

$system = $registry->project('system', $graph, new GraphView());
$assert(in_array('capability:sales.pipeline', $ids($system), true), 'System view lost capabilities.');
$assert(!in_array('event:deal.changed', $ids($system), true), 'System view leaked runtime events.');
$assert(!in_array('rule:sales:followup', $ids($system), true), 'System view leaked runtime rules.');
$assert(!in_array('contract:property-reference', $ids($system), true), 'System view leaked contract topology.');
$assert(!in_array('depends_on', $relations($system), true), 'System view must not mix dependency topology into composition.');

$runtime = $registry->project('runtime', $graph, new GraphView());
foreach (['event:deal.changed', 'rule:sales:followup', 'action:deal.close', 'policy:sales:deal.close', 'handler:close', 'agent:sales.agent'] as $id) {
    $assert(in_array($id, $ids($runtime), true), 'Runtime view lost node: ' . $id);
}
foreach (['triggers', 'produces', 'governs', 'handled_by', 'proposes'] as $relation) {
    $assert(in_array($relation, $relations($runtime), true), 'Runtime view lost relation: ' . $relation);
}
$assert(!in_array('service:salesRoute', $ids($runtime), true), 'Runtime view leaked services.');
$assert(!in_array('contract:property-reference', $ids($runtime), true), 'Runtime view leaked contracts.');

$dependencies = $registry->project('dependencies', $graph, new GraphView());
$assert(array_diff(array_unique($relations($dependencies)), ['contains', 'depends_on', 'requires_contract', 'provides_contract', 'contributes', 'contributes_to']) === [], 'Dependency view contains unexpected relations.');
$assert(in_array('domain:property', $ids($dependencies), true), 'Dependency view lost dependent domain.');
$assert(in_array('contract:property-reference', $ids($dependencies), true), 'Dependency view lost contract topology.');
$assert(in_array('extension_point:web.navigation', $ids($dependencies), true), 'Dependency view lost extension topology.');

$contracts = $registry->project('contracts', $graph, new GraphView());
foreach (['domain:sales', 'domain:property', 'contract:property-reference'] as $id) {
    $assert(in_array($id, $ids($contracts), true), 'Contracts view lost node: ' . $id);
}
foreach (['owns', 'requires_contract', 'provides_contract'] as $relation) {
    $assert(in_array($relation, $relations($contracts), true), 'Contracts view lost relation: ' . $relation);
}
$assert(array_diff(array_unique($relations($contracts)), ['owns', 'requires_contract', 'provides_contract']) === [], 'Contracts view contains unexpected relations.');
$assert(!in_array('capability:sales.pipeline', $ids($contracts), true), 'Contracts view leaked capability topology.');

$events = $registry->project('events', $graph, new GraphView());
foreach (['event:deal.changed', 'rule:sales:followup', 'action:deal.close'] as $id) {
    $assert(in_array($id, $ids($events), true), 'Events flow lost node: ' . $id);
}
$assert(!in_array('policy:sales:deal.close', $ids($events), true), 'Events view leaked policy nodes.');

$actions = $registry->project('actions', $graph, new GraphView());
foreach (['rule:sales:followup', 'action:deal.close', 'policy:sales:deal.close', 'handler:close', 'agent:sales.agent'] as $id) {
    $assert(in_array($id, $ids($actions), true), 'Actions flow lost node: ' . $id);
}
foreach (['produces', 'governs', 'handled_by', 'proposes'] as $relation) {
    $assert(in_array($relation, $relations($actions), true), 'Actions flow lost relation: ' . $relation);
}

$agents = $registry->project('agents', $graph, new GraphView());
$assert(in_array('agent:sales.agent', $ids($agents), true) && !in_array('handler:close', $ids($agents), true), 'Agent view boundary is invalid.');

$integrations = $registry->project('integrations', $graph, new GraphView());
$assert(in_array('extension_point:web.navigation', $ids($integrations), true), 'Integration view lost extension points.');
$assert(array_diff(array_unique($relations($integrations)), ['contributes', 'contributes_to']) === [], 'Integration view contains non-integration relations.');

$code = $registry->project('code', $graph, new GraphView());
$assert(in_array('handler:close', $ids($code), true) && in_array('service:salesRoute', $ids($code), true), 'Code view lost implementation nodes.');

$domain = $registry->project('domain', $graph, new GraphView(focus: 'domain:sales', depth: 1));
foreach (['event:deal.changed', 'rule:sales:followup', 'policy:sales:deal.close', 'domain:property', 'contract:property-reference'] as $id) {
    $assert(in_array($id, $ids($domain), true), 'Domain depth=1 lost directly connected node: ' . $id);
}
$assert(!in_array('handler:close', $ids($domain), true), 'Domain depth=1 unexpectedly crossed two relations.');

$filtered = $registry->project('system', $graph, new GraphView(filters: new GraphFilter(nodeTypes: ['domain'])));
$assert($ids($filtered) === ['domain:sales', 'domain:property'], 'GraphView node type filter was ignored by projection.');

try {
    $registry->project('missing', $graph, new GraphView());
    throw new RuntimeException('Unknown projection did not fail.');
} catch (RuntimeException $exception) {
    $assert(str_contains($exception->getMessage(), 'Unknown architecture projection'), 'Unknown projection failed for the wrong reason.');
}

echo "Visualization architecture projection invariants passed.\n";
