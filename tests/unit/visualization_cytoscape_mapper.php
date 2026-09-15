<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);
require $root . '/vendor/autoload.php';

use Infrastructure\Visualization\Cytoscape\CytoscapeGraphMapper;
use Kernel\Visualization\Graph\Edge;
use Kernel\Visualization\Graph\Graph;
use Kernel\Visualization\Graph\Group;
use Kernel\Visualization\Graph\Node;

$assert = static function (bool $condition, string $message): void {
    if (!$condition) {
        throw new RuntimeException($message);
    }
};

$graph = new Graph(
    [
        new Node('domain:sales', 'domain', 'Sales', 'group:domains', ['module_id' => 'sales']),
        new Node('event:deal.changed', 'event', 'deal.changed', metadata: ['event_type' => 'deal.changed']),
    ],
    [new Edge('edge:1', 'domain:sales', 'event:deal.changed', 'owns', ['source' => 'runtime'])],
    [new Group('group:domains', 'Domains')],
);

$payload = (new CytoscapeGraphMapper())->map($graph);
$assert(count($payload['elements']) === 4, 'Cytoscape mapper must emit group, nodes and edge elements.');
$assert(($payload['summary']['nodes'] ?? null) === 2, 'Node summary mismatch.');
$assert(($payload['summary']['edges'] ?? null) === 1, 'Edge summary mismatch.');
$assert(($payload['summary']['groups'] ?? null) === 1, 'Group summary mismatch.');
$assert(($payload['summary']['node_types']['domain'] ?? null) === 1, 'Domain type summary missing.');
$assert(($payload['summary']['relations']['owns'] ?? null) === 1, 'Relation summary missing.');
$assert(($payload['summary']['domains'][0]['module_id'] ?? null) === 'sales', 'Domain selector metadata missing.');

$byId = [];
foreach ($payload['elements'] as $element) {
    $byId[(string) ($element['data']['id'] ?? '')] = $element;
}
$assert(($byId['domain:sales']['group'] ?? null) === 'nodes', 'Node must use Cytoscape nodes group.');
$assert(($byId['domain:sales']['data']['parent'] ?? null) === 'group:domains', 'Compound parent mapping missing.');
$assert(($byId['edge:1']['group'] ?? null) === 'edges', 'Edge must use Cytoscape edges group.');
$assert(($byId['edge:1']['data']['relation'] ?? null) === 'owns', 'Edge relation mapping missing.');

echo "Visualization Cytoscape mapper invariants passed.\n";
