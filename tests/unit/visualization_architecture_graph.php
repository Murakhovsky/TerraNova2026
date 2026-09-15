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
use Kernel\Module\Contract\EventOwningModuleInterface;
use Kernel\Module\DomainModuleInterface;
use Kernel\Module\DomainModuleRegistry;
use Kernel\Module\ModuleCatalog;
use Kernel\Module\ModuleContributions;
use Kernel\Module\ModuleDefinition;
use Kernel\Module\ModuleManifest;
use Kernel\Rule\Contract\RuleContextProviderInterface;
use Kernel\Visualization\Graph\Edge;

$ruleContext = new class implements RuleContextProviderInterface {
    public function contextFor(DomainEvent $event): array
    {
        return [];
    }
};

$handler = new class implements ActionHandlerInterface {
    public function supports(string $actionType): bool
    {
        return $actionType === 'AssignLead';
    }

    public function execute(Action $action): ExecutionResult
    {
        throw new RuntimeException('Not executed by visualization contract test.');
    }
};

$agentContext = new class implements AgentContextBuilderInterface {
    public function build(AgentInvocation $invocation): array
    {
        return [];
    }
};

$runtimeModule = new class($ruleContext, $handler, $agentContext) implements DomainModuleInterface, EventOwningModuleInterface, ActionOwningModuleInterface, AgentProvidingModuleInterface {
    public function __construct(
        private RuleContextProviderInterface $ruleContext,
        private ActionHandlerInterface $handler,
        private AgentContextBuilderInterface $agentContext,
    ) {
    }

    public function name(): string
    {
        return 'sales';
    }

    public function eventTypes(): array
    {
        return ['LeadCreated'];
    }

    public function ruleContextProvider(): RuleContextProviderInterface
    {
        return $this->ruleContext;
    }

    public function actionTypes(): array
    {
        return ['AssignLead'];
    }

    public function actionHandlers(): array
    {
        return [$this->handler];
    }

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

    public function agentContextBuilders(): array
    {
        return ['sales_assistant' => $this->agentContext];
    }
};

$catalog = new ModuleCatalog([
    new ModuleDefinition(
        new ModuleManifest('core', 'Core', '1.0.0'),
        new ModuleContributions(),
    ),
    new ModuleDefinition(
        new ModuleManifest('sales', 'Sales', '1.0.0', dependencies: ['core']),
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
    'action:AssignLead',
    'agent:sales_assistant',
    'service:salesDomainModule',
    'extension_point:event.consumers',
] as $nodeId) {
    if (!$graph->hasNode($nodeId)) {
        throw new RuntimeException('Architecture graph missing node: ' . $nodeId);
    }
}

$hasEdge = static function (array $edges, string $source, string $target, string $relation): bool {
    foreach ($edges as $edge) {
        if ($edge instanceof Edge && $edge->source === $source && $edge->target === $target && $edge->relation === $relation) {
            return true;
        }
    }
    return false;
};

foreach ([
    ['domain:sales', 'domain:core', 'depends_on'],
    ['domain:sales', 'capability:sales.manage', 'owns'],
    ['domain:sales', 'event:LeadCreated', 'owns'],
    ['domain:sales', 'action:AssignLead', 'owns'],
    ['domain:sales', 'agent:sales_assistant', 'owns'],
    ['agent:sales_assistant', 'action:AssignLead', 'proposes'],
    ['service:salesHistoricalConsumer', 'extension_point:event.consumers', 'contributes_to'],
] as [$source, $target, $relation]) {
    if (!$hasEdge($graph->edges(), $source, $target, $relation)) {
        throw new RuntimeException(sprintf('Architecture graph missing edge %s -[%s]-> %s.', $source, $relation, $target));
    }
}

echo "Visualization architecture graph invariants passed.\n";
