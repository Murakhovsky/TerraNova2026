<?php
declare(strict_types=1);

use Infrastructure\Visualization\Architecture\ArchitectureGraphVocabulary;
use Infrastructure\Visualization\Architecture\CrossDomainArchitectureGraphProvider;
use Kernel\Module\CrossDomainContract;
use Kernel\Module\ModuleCatalog;
use Kernel\Module\ModuleContributions;
use Kernel\Module\ModuleDefinition;
use Kernel\Module\ModuleManifest;
use Kernel\Visualization\Graph\Graph;
use Kernel\Visualization\Graph\GraphProviderInterface;
use Kernel\Visualization\Graph\Node;

$root = dirname(__DIR__, 2);
require $root . '/vendor/autoload.php';

$assert = static function (bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
};

$parsed = ModuleContributions::fromArray([
    'cross_domain_contracts' => [[
        'contract' => 'Domains\\Property\\Contract\\PropertyReferencePort',
        'role' => 'requires',
        'counterpart' => 'property',
        'kind' => 'synchronous_port',
        'purpose' => 'test',
    ]],
]);
$assert(count($parsed->crossDomainContracts) === 1, 'Cross-domain contract contribution was not parsed.');
$assert($parsed->crossDomainContracts[0]->consumerDomain('sales') === 'sales', 'Requires role consumer is wrong.');
$assert($parsed->crossDomainContracts[0]->providerDomain('sales') === 'property', 'Requires role provider is wrong.');

$sales = new ModuleDefinition(
    new ModuleManifest('sales', 'Sales', '1.0.0'),
    new ModuleContributions(crossDomainContracts: [
        new CrossDomainContract(
            'Domains\\Property\\Contract\\PropertyReferencePort',
            CrossDomainContract::ROLE_REQUIRES,
            'property',
            'synchronous_port',
            'Resolve Property reference.',
        ),
    ]),
);
$catalog = new ModuleCatalog([$sales]);
$base = new class implements GraphProviderInterface {
    public function provide(): Graph
    {
        return new Graph([
            new Node('kernel:cos', ArchitectureGraphVocabulary::TYPE_KERNEL, 'COS Kernel'),
            new Node('domain:sales', ArchitectureGraphVocabulary::TYPE_DOMAIN, 'Sales'),
        ]);
    }
};

$graph = (new CrossDomainArchitectureGraphProvider($base, $catalog))->provide();
$nodes = [];
foreach ($graph->nodes() as $node) $nodes[$node->id] = $node;
$assert(isset($nodes['domain:property']), 'Counterpart domain must be materialized from contract declaration.');
$contracts = array_values(array_filter($graph->nodes(), static fn (Node $node): bool => $node->type === ArchitectureGraphVocabulary::TYPE_CONTRACT));
$assert(count($contracts) === 1, 'Contract node was not materialized.');
$contract = $contracts[0];
$assert(($contract->metadata['consumer_domain'] ?? null) === 'sales', 'Contract consumer metadata is wrong.');
$assert(($contract->metadata['provider_domain'] ?? null) === 'property', 'Contract provider metadata is wrong.');

$relations = [];
foreach ($graph->edges() as $edge) {
    $relations[$edge->source . '|' . $edge->relation . '|' . $edge->target] = true;
}
$assert(isset($relations['domain:sales|requires_contract|' . $contract->id]), 'Consumer requires_contract edge missing.');
$assert(isset($relations['domain:property|provides_contract|' . $contract->id]), 'Provider provides_contract edge missing.');
$assert(!isset($relations['domain:sales|depends_on|domain:property']), 'Contract topology must not be collapsed into module depends_on.');

echo "Visualization V0.4.2 cross-domain contract invariants passed.\n";
