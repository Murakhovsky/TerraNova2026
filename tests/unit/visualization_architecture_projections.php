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
    new Node('event:deal.changed', 'event', 'deal.changed'),
    new Node('action:deal.close', 'action', 'deal.close'),
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
    new Edge('e6', 'domain:sales', 'action:deal.close', 'owns'),
    new Edge('e7', 'action:deal.close', 'handler:close', 'handled_by'),
    new Edge('e8', 'domain:sales', 'agent:sales.agent', 'owns'),
    new Edge('e9', 'agent:sales.agent', 'action:deal.close', 'proposes'),
    new Edge('e10', 'domain:sales', 'service:salesRoute', 'contributes'),
    new Edge('e11', 'service:salesRoute', 'extension_point:web.navigation', 'contributes_to'),
];
$graph = new Graph($nodes, $edges);
$registry = ArchitectureProjectionRegistry::defaults();

$assert($registry->names() === ['system', 'runtime', 'domain', 'dependencies', 'events', 'actions', 'agents', 'integrations', 'code'], 'Canonical projection order changed.');
$assert(($registry->descriptions()['dependencies']['label'] ?? null) === 'Dependencies', 'Projection descriptions missing.');

$system = $registry->project('system', $graph, new GraphView());
$assert(in_array('capability:sales.pipeline', $ids($system), true), 'System view lost capabilities.');
$assert(!in_array('event:deal.changed', $ids($system), true), 'System view leaked runtime events.');

$runtime = $registry->project('runtime', $graph, new GraphView());
$assert(in_array('event:deal.changed', $ids($runtime), true), 'Runtime view lost events.');
$assert(in_array('handler:close', $ids($runtime), true), 'Runtime view lost handlers.');
$assert(!in_array('service:salesRoute', $ids($runtime), true), 'Runtime view leaked services.');

$dependencies = $registry->project('dependencies', $graph, new GraphView());
$assert(array_diff(array_unique($relations($dependencies)), ['contains', 'depends_on']) === [], 'Dependency view contains non-dependency relations.');
$assert(in_array('domain:property', $ids($dependencies), true), 'Dependency view lost dependent domain.');

$events = $registry->project('events', $graph, new GraphView());
$assert($ids($events) === ['domain:sales', 'domain:property', 'event:deal.changed'], 'Event view contains unexpected node types.');

$actions = $registry->project('actions', $graph, new GraphView());
$assert(in_array('action:deal.close', $ids($actions), true) && in_array('handler:close', $ids($actions), true), 'Action view lost execution path.');
$assert(in_array('proposes', $relations($actions), true), 'Action view lost agent proposal relation.');

$agents = $registry->project('agents', $graph, new GraphView());
$assert(in_array('agent:sales.agent', $ids($agents), true) && !in_array('handler:close', $ids($agents), true), 'Agent view boundary is invalid.');

$integrations = $registry->project('integrations', $graph, new GraphView());
$assert(in_array('extension_point:web.navigation', $ids($integrations), true), 'Integration view lost extension points.');
$assert(array_diff(array_unique($relations($integrations)), ['contributes', 'contributes_to']) === [], 'Integration view contains non-integration relations.');

$code = $registry->project('code', $graph, new GraphView());
$assert(in_array('handler:close', $ids($code), true) && in_array('service:salesRoute', $ids($code), true), 'Code view lost implementation nodes.');

$domain = $registry->project('domain', $graph, new GraphView(focus: 'domain:sales', depth: 1));
$assert(in_array('event:deal.changed', $ids($domain), true), 'Domain neighborhood lost owned event.');
$assert(in_array('domain:property', $ids($domain), true), 'Domain neighborhood lost dependency.');
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
