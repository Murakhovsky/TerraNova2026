<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);
require $root . '/vendor/autoload.php';

use Infrastructure\Visualization\Architecture\ArchitectureGraphProvider;
use Kernel\Action\Action;
use Kernel\Action\Contract\ActionHandlerInterface;
use Kernel\Action\ExecutionResult;
use Kernel\Agent\AgentDefinition;
use Kernel\Agent\AgentInvocation;
use Kernel\Agent\Contract\AgentContextBuilderInterface;
use Kernel\Event\DomainEvent;
use Kernel\Module\Contract\ActionOwningModuleInterface;
use Kernel\Module\Contract\AgentProvidingModuleInterface;
use Kernel\Module\Contract\BootstrapPolicyProvidingModuleInterface;
use Kernel\Module\Contract\BootstrapRuleProvidingModuleInterface;
use Kernel\Module\Contract\EventOwningModuleInterface;
use Kernel\Module\DomainModuleInterface;
use Kernel\Module\DomainModuleRegistry;
use Kernel\Module\ModuleCatalog;
use Kernel\Module\ModuleContributions;
use Kernel\Module\ModuleDefinition;
use Kernel\Module\ModuleManifest;
use Kernel\Policy\ActionPolicy;
use Kernel\Policy\PolicyDecision;
use Kernel\Rule\Contract\RuleContextProviderInterface;
use Kernel\Rule\Rule;
use Kernel\Visualization\Graph\Edge;

$assert = static function (bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
};

$ruleContext = new class implements RuleContextProviderInterface {
    public function contextFor(DomainEvent $event): array { return []; }
};

$handler = new class implements ActionHandlerInterface {
    public function supports(string $actionType): bool { return $actionType === 'AssignLead'; }
    public function execute(Action $action): ExecutionResult
    {
        throw new RuntimeException('Not executed by visualization contract test.');
    }
};

$agentContext = new class implements AgentContextBuilderInterface {
    public function build(AgentInvocation $invocation): array { return []; }
};

$runtimeModule = new class($ruleContext, $handler, $agentContext) implements
    DomainModuleInterface,
    EventOwningModuleInterface,
    ActionOwningModuleInterface,
    AgentProvidingModuleInterface,
    BootstrapRuleProvidingModuleInterface,
    BootstrapPolicyProvidingModuleInterface
{
    public function __construct(
        private RuleContextProviderInterface $ruleContext,
        private ActionHandlerInterface $handler,
        private AgentContextBuilderInterface $agentContext,
    ) {}

    public function name(): string { return 'sales'; }
    public function eventTypes(): array { return ['LeadCreated']; }
    public function ruleContextProvider(): RuleContextProviderInterface { return $this->ruleContext; }
    public function actionTypes(): array { return ['AssignLead']; }
    public function actionHandlers(): array { return [$this->handler]; }

    public function agents(): array
    {
        return [
            'sales_assistant' => new AgentDefinition(
                name: 'sales_assistant',
                version: '1.0.0',
                systemPrompt: 'test',
                promptVersion: '1.0.0',
                schemaVersion: '1.0.0',
                allowedActionTypes: ['AssignLead'],
                domainName: 'sales',
            ),
        ];
    }

    public function agentContextBuilders(): array { return ['sales_assistant' => $this->agentContext]; }

    public function bootstrapRules(): array
    {
        return [new Rule(
            'assign-lead-v1',
            'default',
            'Assign new lead',
            'LeadCreated',
            [],
            ['type' => 'CREATE_ACTION', 'action_type' => 'AssignLead'],
            priority: 10,
        )];
    }

    public function bootstrapPolicies(): array
    {
        return [new ActionPolicy(
            'assign-lead-policy-v1',
            'default',
            'AssignLead',
            [],
            PolicyDecision::ApprovalRequired,
            10,
            'Assign lead approval',
        )];
    }
};

$catalog = new ModuleCatalog([
    new ModuleDefinition(
        new ModuleManifest('core', 'Core', '1.0.0', kernelConstraint: '>=0.11.0 <0.12.0'),
        new ModuleContributions(),
    ),
    new ModuleDefinition(
        new ModuleManifest('sales', 'Sales', '1.0.0', dependencies: ['core'], kernelConstraint: '>=0.11.0 <0.12.0'),
        new ModuleContributions(
            runtimeModuleService: 'salesDomainModule',
            jobHandlerServices: ['salesInboxJob'],
            capabilities: ['sales.manage'],
            extensionServices: ['event.consumers' => ['salesHistoricalConsumer']],
        ),
    ),
]);
$registry = new DomainModuleRegistry([$runtimeModule]);
$graph = (new ArchitectureGraphProvider($catalog, $registry))->provide();

foreach ([
    'kernel:cos',
    'domain:core',
    'domain:sales',
    'capability:sales.manage',
    'event:LeadCreated',
    'rule:sales:assign-lead-v1',
    'action:AssignLead',
    'policy:sales:assign-lead-policy-v1',
    'agent:sales_assistant',
    'service:salesDomainModule',
    'extension_point:event.consumers',
] as $nodeId) {
    $assert($graph->hasNode($nodeId), 'Architecture graph missing node: ' . $nodeId);
}

$findEdge = static function (array $edges, string $source, string $target, string $relation): ?Edge {
    foreach ($edges as $edge) {
        if ($edge instanceof Edge && $edge->source === $source && $edge->target === $target && $edge->relation === $relation) {
            return $edge;
        }
    }
    return null;
};

foreach ([
    ['domain:sales', 'kernel:cos', 'depends_on'],
    ['domain:sales', 'domain:core', 'depends_on'],
    ['domain:sales', 'capability:sales.manage', 'owns'],
    ['domain:sales', 'event:LeadCreated', 'owns'],
    ['domain:sales', 'rule:sales:assign-lead-v1', 'owns'],
    ['event:LeadCreated', 'rule:sales:assign-lead-v1', 'triggers'],
    ['rule:sales:assign-lead-v1', 'action:AssignLead', 'produces'],
    ['domain:sales', 'action:AssignLead', 'owns'],
    ['domain:sales', 'policy:sales:assign-lead-policy-v1', 'owns'],
    ['policy:sales:assign-lead-policy-v1', 'action:AssignLead', 'governs'],
    ['domain:sales', 'agent:sales_assistant', 'owns'],
    ['agent:sales_assistant', 'action:AssignLead', 'proposes'],
    ['service:salesHistoricalConsumer', 'extension_point:event.consumers', 'contributes_to'],
] as [$source, $target, $relation]) {
    $assert($findEdge($graph->edges(), $source, $target, $relation) instanceof Edge, sprintf(
        'Architecture graph missing edge %s -[%s]-> %s.',
        $source,
        $relation,
        $target,
    ));
}

$kernelDependency = $findEdge($graph->edges(), 'domain:sales', 'kernel:cos', 'depends_on');
$assert(($kernelDependency?->metadata['source'] ?? null) === 'manifest.kernel_constraint', 'Kernel dependency must preserve manifest provenance.');
$assert(($kernelDependency?->metadata['constraint'] ?? null) === '>=0.11.0 <0.12.0', 'Kernel dependency constraint was lost.');

$rule = $graph->node('rule:sales:assign-lead-v1');
$assert(($rule->metadata['scope'] ?? null) === 'bootstrap_default', 'Architecture rules must identify bootstrap scope.');
$assert(($rule->metadata['trigger'] ?? null) === 'LeadCreated', 'Architecture rule trigger metadata missing.');

$policy = $graph->node('policy:sales:assign-lead-policy-v1');
$assert(($policy->metadata['scope'] ?? null) === 'bootstrap_default', 'Architecture policies must identify bootstrap scope.');
$assert(($policy->metadata['decision'] ?? null) === 'APPROVAL_REQUIRED', 'Architecture policy decision metadata missing.');

echo "Visualization architecture graph invariants passed.\n";
