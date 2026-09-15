<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);
require $root . '/vendor/autoload.php';

use Kernel\Visualization\Graph\Edge;
use Kernel\Visualization\Graph\Graph;
use Kernel\Visualization\Graph\GraphFilter;
use Kernel\Visualization\Graph\GraphProjectionInterface;
use Kernel\Visualization\Graph\GraphView;
use Kernel\Visualization\Graph\Group;
use Kernel\Visualization\Graph\Node;

$graph = new Graph(
    [
        new Node('domain:sales', 'domain', 'Sales', 'group:domains', ['version' => '1.0.0']),
        new Node('event:LeadCreated', 'event', 'LeadCreated', 'domain:sales'),
    ],
    [
        new Edge('edge:1', 'domain:sales', 'event:LeadCreated', 'owns'),
    ],
    [
        new Group('group:domains', 'Domains'),
    ],
);

if (!$graph->hasNode('domain:sales') || $graph->node('event:LeadCreated')->type !== 'event') {
    throw new RuntimeException('Graph node registry failed.');
}
if (!$graph->hasGroup('group:domains') || count($graph->edges()) !== 1) {
    throw new RuntimeException('Graph group/edge registry failed.');
}

$view = new GraphView(
    focus: 'domain:sales',
    depth: 2,
    layout: 'hierarchical',
    filters: new GraphFilter(['domain', 'event'], ['owns']),
);
if ($view->filters->nodeTypes !== ['domain', 'event'] || $view->depth !== 2) {
    throw new RuntimeException('Graph view contract failed.');
}

$projection = new class implements GraphProjectionInterface {
    public function project(Graph $graph, GraphView $view): Graph
    {
        return $graph;
    }
};
if ($projection->project($graph, $view) !== $graph) {
    throw new RuntimeException('Graph projection contract failed.');
}

$payload = $graph->toArray();
if (count($payload['nodes']) !== 2 || count($payload['edges']) !== 1 || count($payload['groups']) !== 1) {
    throw new RuntimeException('Graph serialization contract failed.');
}

try {
    new Graph([new Node('a', 'domain', 'A')], [new Edge('bad', 'a', 'missing', 'depends_on')]);
    throw new RuntimeException('Graph accepted an edge with an unknown target.');
} catch (InvalidArgumentException) {
}

try {
    new Graph([new Node('a', 'domain', 'A'), new Node('a', 'domain', 'Duplicate')]);
    throw new RuntimeException('Graph accepted duplicate node ids.');
} catch (InvalidArgumentException) {
}

echo "Visualization graph foundation invariants passed.\n";
