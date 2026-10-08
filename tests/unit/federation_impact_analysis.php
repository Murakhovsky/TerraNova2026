<?php
declare(strict_types=1);

use Infrastructure\Visualization\Architecture\ArchitectureGraphVocabulary as V;
use Infrastructure\Visualization\Architecture\ArchitectureImpactAnalysisService;
use Kernel\Visualization\Graph\Edge;
use Kernel\Visualization\Graph\Graph;
use Kernel\Visualization\Graph\GraphProviderInterface;
use Kernel\Visualization\Graph\Node;

$root = dirname(__DIR__, 2);
require $root . '/vendor/autoload.php';

$provider = new class implements GraphProviderInterface {
    public function provide(): Graph
    {
        return new Graph([
            new Node('contract:sales', V::TYPE_CONTRACT, 'Sales Contract', metadata: ['tests' => ['tests/unit/federation_cross_domain_contracts_v2.php']]),
            new Node('domain:growth', V::TYPE_DOMAIN, 'Growth'),
            new Node('domain:reporting', V::TYPE_DOMAIN, 'Reporting'),
            new Node('domain:sales', V::TYPE_DOMAIN, 'Sales'),
            new Node('capability:growth.research', V::TYPE_CAPABILITY, 'Growth Research'),
        ], [
            new Edge('requires', 'domain:growth', 'contract:sales', V::REL_REQUIRES_CONTRACT, ['source' => 'module.manifest']),
            new Edge('depends', 'domain:reporting', 'domain:growth', V::REL_DEPENDS_ON, ['source' => 'module.dependencies']),
            new Edge('owns', 'domain:growth', 'capability:growth.research', V::REL_OWNS),
        ]);
    }
};
$service = new ArchitectureImpactAnalysisService($provider);
$r = $service->analyze(['contract:sales', 'nonexistent:node']);
if ($r['direct_dependents'] !== ['domain:growth'] || $r['transitive_dependents'] !== ['domain:reporting']) {
    throw new RuntimeException('Reverse/transitive impact traversal is incorrect.');
}
if ($r['affected_domains'] !== ['domain:growth', 'domain:reporting']) {
    throw new RuntimeException('Affected domain projection incorrect.');
}
if ($r['unknown_nodes'] !== ['nonexistent:node'] || $r['risk'] !== 'UNKNOWN_INPUT') {
    throw new RuntimeException('Unknown impact must not be silently dropped.');
}
if ($r['recommended_tests'] !== ['tests/unit/federation_cross_domain_contracts_v2.php']
    || $r['compatibility_verdict'] !== 'UNKNOWN_REQUIRES_CONTRACT_TESTS') {
    throw new RuntimeException('Impact report overclaimed tests or compatibility.');
}
$cap = $service->analyze(['capability:growth.research']);
if ($cap['affected_domains'] !== ['domain:growth'] || $cap['affected_capabilities'] !== ['capability:growth.research']) {
    throw new RuntimeException('Changed capability owner attribution missing.');
}
try {
    $service->analyze([]);
    throw new RuntimeException('Expected empty impact request rejection.');
} catch (InvalidArgumentException) {
}

echo "Federation initial architecture impact analysis passed.\n";
