<?php
declare(strict_types=1);

namespace Infrastructure\Visualization\Architecture;

use Kernel\Module\Contract\ActionOwningModuleInterface;
use Kernel\Module\Contract\AgentProvidingModuleInterface;
use Kernel\Module\Contract\BootstrapPolicyProvidingModuleInterface;
use Kernel\Module\Contract\BootstrapRuleProvidingModuleInterface;
use Kernel\Module\Contract\EventOwningModuleInterface;
use Kernel\Module\DomainModuleInterface;
use Kernel\Module\DomainModuleRegistry;
use Kernel\Module\ModuleCatalog;
use Kernel\Policy\ActionPolicy;
use Kernel\Rule\Rule;
use Kernel\Visualization\Graph\Edge;
use Kernel\Visualization\Graph\Graph;
use Kernel\Visualization\Graph\GraphProviderInterface;
use Kernel\Visualization\Graph\Node;

final readonly class ArchitectureGraphProvider implements GraphProviderInterface
{
    public function __construct(
        private ModuleCatalog $modules,
        private DomainModuleRegistry $runtimeModules,
    ) {
    }

    public function provide(): Graph
    {
        /** @var array<string, Node> $nodes */
        $nodes = [];
        /** @var array<string, Edge> $edges */
        $edges = [];
        /** @var array<string, string> $eventOwners */
        $eventOwners = [];
        /** @var array<string, string> $actionOwners */
        $actionOwners = [];

        $kernelId = 'kernel:cos';
        $nodes[$kernelId] = new Node(
            $kernelId,
            ArchitectureGraphVocabulary::TYPE_KERNEL,
            'COS Kernel',
            metadata: ['role' => 'platform_kernel'],
        );

        foreach ($this->modules->definitions() as $definition) {
            $manifest = $definition->manifest;
            $domainId = $this->domainId($manifest->id);

            $nodes[$domainId] = new Node(
                $domainId,
                ArchitectureGraphVocabulary::TYPE_DOMAIN,
                $manifest->name,
                metadata: [
                    'module_id' => $manifest->id,
                    'version' => $manifest->version,
                    'schema_version' => $manifest->schemaVersion,
                    'description' => $manifest->description,
                    'icon' => $manifest->icon,
                    'enabled_by_default' => $manifest->enabledByDefault,
                    'kernel_constraint' => $manifest->kernelConstraint,
                    'source_path' => $definition->sourcePath,
                ],
            );
            $this->addEdge(
                $edges,
                $kernelId,
                $domainId,
                ArchitectureGraphVocabulary::REL_CONTAINS,
                ['source' => 'module_catalog'],
            );
            $this->addEdge(
                $edges,
                $domainId,
                $kernelId,
                ArchitectureGraphVocabulary::REL_DEPENDS_ON,
                [
                    'source' => 'manifest.kernel_constraint',
                    'constraint' => $manifest->kernelConstraint,
                    'dependency_kind' => 'kernel_contract',
                ],
            );

            foreach ($manifest->dependencies as $dependency) {
                $this->addEdge(
                    $edges,
                    $domainId,
                    $this->domainId($dependency),
                    ArchitectureGraphVocabulary::REL_DEPENDS_ON,
                    [
                        'source' => 'manifest.dependencies',
                        'constraint' => $manifest->constraintFor($dependency),
                        'dependency_kind' => 'module_manifest',
                    ],
                );
            }

            foreach ($definition->contributions->capabilities as $capability) {
                $capabilityId = 'capability:' . $capability;
                $nodes[$capabilityId] ??= new Node(
                    $capabilityId,
                    ArchitectureGraphVocabulary::TYPE_CAPABILITY,
                    $capability,
                    metadata: ['capability' => $capability],
                );
                $this->addEdge(
                    $edges,
                    $domainId,
                    $capabilityId,
                    ArchitectureGraphVocabulary::REL_OWNS,
                    ['source' => 'module.contributions.capabilities'],
                );
            }

            foreach ($definition->contributions->allServiceIds() as $serviceId) {
                $nodeId = 'service:' . $serviceId;
                $nodes[$nodeId] ??= new Node(
                    $nodeId,
                    ArchitectureGraphVocabulary::TYPE_SERVICE,
                    $serviceId,
                    metadata: ['service_id' => $serviceId],
                );
                $this->addEdge(
                    $edges,
                    $domainId,
                    $nodeId,
                    ArchitectureGraphVocabulary::REL_CONTRIBUTES,
                    ['source' => 'module.contributions.services'],
                );
            }

            foreach ($definition->contributions->normalizedExtensionServices() as $extensionPoint => $serviceIds) {
                $extensionId = 'extension_point:' . $extensionPoint;
                $nodes[$extensionId] ??= new Node(
                    $extensionId,
                    ArchitectureGraphVocabulary::TYPE_EXTENSION_POINT,
                    $extensionPoint,
                    metadata: ['extension_point' => $extensionPoint],
                );

                foreach ($serviceIds as $serviceId) {
                    $serviceNodeId = 'service:' . $serviceId;
                    $nodes[$serviceNodeId] ??= new Node(
                        $serviceNodeId,
                        ArchitectureGraphVocabulary::TYPE_SERVICE,
                        $serviceId,
                        metadata: ['service_id' => $serviceId],
                    );
                    $this->addEdge(
                        $edges,
                        $serviceNodeId,
                        $extensionId,
                        ArchitectureGraphVocabulary::REL_CONTRIBUTES_TO,
                        ['source' => 'module.contributions.extension_services'],
                    );
                }
            }
        }

        $runtimeModules = $this->runtimeModules->modules();
        foreach ($runtimeModules as $module) {
            $this->ensureRuntimeDomain($nodes, $edges, $kernelId, $module);
        }

        // Build canonical ownership first so later automation relations can safely
        // detect cross-domain dependencies without guessing from source code.
        foreach ($runtimeModules as $module) {
            $domainId = $this->domainId($module->name());
            $this->appendRuntimeEvents($nodes, $edges, $domainId, $module, $eventOwners);
            $this->appendRuntimeActions($nodes, $edges, $domainId, $module, $actionOwners);
        }

        foreach ($runtimeModules as $module) {
            $domainId = $this->domainId($module->name());
            $this->appendRuntimeAgents($nodes, $edges, $domainId, $module, $actionOwners);
            $this->appendBootstrapRules($nodes, $edges, $domainId, $module, $eventOwners, $actionOwners);
            $this->appendBootstrapPolicies($nodes, $edges, $domainId, $module, $actionOwners);
        }

        return new Graph(array_values($nodes), array_values($edges));
    }

    /** @param array<string, Node> $nodes @param array<string, Edge> $edges */
    private function ensureRuntimeDomain(array &$nodes, array &$edges, string $kernelId, DomainModuleInterface $module): void
    {
        $domainId = $this->domainId($module->name());
        if (isset($nodes[$domainId])) {
            return;
        }

        $nodes[$domainId] = new Node(
            $domainId,
            ArchitectureGraphVocabulary::TYPE_DOMAIN,
            ucfirst($module->name()),
            metadata: ['module_id' => $module->name(), 'runtime_only' => true],
        );
        $this->addEdge(
            $edges,
            $kernelId,
            $domainId,
            ArchitectureGraphVocabulary::REL_CONTAINS,
            ['source' => 'runtime_module_registry'],
        );
        $this->addEdge(
            $edges,
            $domainId,
            $kernelId,
            ArchitectureGraphVocabulary::REL_DEPENDS_ON,
            [
                'source' => 'runtime_module_contract',
                'constraint' => '*',
                'dependency_kind' => 'kernel_contract',
            ],
        );
    }

    /** @param array<string, Node> $nodes @param array<string, Edge> $edges @param array<string,string> $eventOwners */
    private function appendRuntimeEvents(
        array &$nodes,
        array &$edges,
        string $domainId,
        DomainModuleInterface $module,
        array &$eventOwners,
    ): void {
        if (!$module instanceof EventOwningModuleInterface) {
            return;
        }

        foreach ($module->eventTypes() as $eventType) {
            $nodeId = 'event:' . $eventType;
            $nodes[$nodeId] ??= new Node(
                $nodeId,
                ArchitectureGraphVocabulary::TYPE_EVENT,
                $eventType,
                metadata: ['event_type' => $eventType],
            );
            $eventOwners[$eventType] ??= $domainId;
            $this->addEdge(
                $edges,
                $domainId,
                $nodeId,
                ArchitectureGraphVocabulary::REL_OWNS,
                ['source' => 'runtime.event_ownership'],
            );
        }
    }

    /** @param array<string, Node> $nodes @param array<string, Edge> $edges @param array<string,string> $actionOwners */
    private function appendRuntimeActions(
        array &$nodes,
        array &$edges,
        string $domainId,
        DomainModuleInterface $module,
        array &$actionOwners,
    ): void {
        if (!$module instanceof ActionOwningModuleInterface) {
            return;
        }

        $handlers = $module->actionHandlers();
        foreach ($module->actionTypes() as $actionType) {
            $nodeId = 'action:' . $actionType;
            $nodes[$nodeId] ??= new Node(
                $nodeId,
                ArchitectureGraphVocabulary::TYPE_ACTION,
                $actionType,
                metadata: ['action_type' => $actionType],
            );
            $actionOwners[$actionType] ??= $domainId;
            $this->addEdge(
                $edges,
                $domainId,
                $nodeId,
                ArchitectureGraphVocabulary::REL_OWNS,
                ['source' => 'runtime.action_ownership'],
            );

            foreach ($handlers as $handler) {
                if (!$handler->supports($actionType)) {
                    continue;
                }
                $handlerClass = $handler::class;
                $handlerId = 'handler:' . $handlerClass;
                $nodes[$handlerId] ??= new Node(
                    $handlerId,
                    ArchitectureGraphVocabulary::TYPE_HANDLER,
                    $handlerClass,
                    metadata: ['class' => $handlerClass],
                );
                $this->addEdge(
                    $edges,
                    $nodeId,
                    $handlerId,
                    ArchitectureGraphVocabulary::REL_HANDLED_BY,
                    ['source' => 'runtime.action_handlers'],
                );
            }
        }
    }

    /** @param array<string, Node> $nodes @param array<string, Edge> $edges @param array<string,string> $actionOwners */
    private function appendRuntimeAgents(
        array &$nodes,
        array &$edges,
        string $domainId,
        DomainModuleInterface $module,
        array $actionOwners,
    ): void {
        if (!$module instanceof AgentProvidingModuleInterface) {
            return;
        }

        foreach ($module->agents() as $name => $definition) {
            $nodeId = 'agent:' . $name;
            $nodes[$nodeId] ??= new Node(
                $nodeId,
                ArchitectureGraphVocabulary::TYPE_AGENT,
                $name,
                metadata: [
                    'version' => $definition->version,
                    'prompt_version' => $definition->promptVersion,
                    'schema_version' => $definition->schemaVersion,
                    'execution_mode' => $definition->defaultExecutionMode,
                    'risk_level' => $definition->defaultRiskLevel,
                    'domain_name' => $definition->domainName,
                    'enabled' => $definition->enabled,
                    'profile' => $definition->profile,
                    'model' => $definition->model,
                    'context_sources' => $definition->contextSources,
                    'confidence_threshold' => $definition->confidenceThreshold,
                    'max_actions_per_run' => $definition->maxActionsPerRun,
                ],
            );
            $this->addEdge(
                $edges,
                $domainId,
                $nodeId,
                ArchitectureGraphVocabulary::REL_OWNS,
                ['source' => 'runtime.agent_ownership'],
            );

            foreach ($definition->allowedActionTypes as $actionType) {
                $actionId = $this->ensureReferencedAction($nodes, $actionType, 'agent.allowed_action_types');
                $this->addEdge(
                    $edges,
                    $nodeId,
                    $actionId,
                    ArchitectureGraphVocabulary::REL_PROPOSES,
                    ['source' => 'agent.allowed_action_types'],
                );
                $this->appendDerivedDomainDependency(
                    $edges,
                    $domainId,
                    $actionOwners[$actionType] ?? null,
                    'automation.agent',
                    ['agent' => $name, 'action_type' => $actionType],
                );
            }
        }
    }

    /**
     * @param array<string, Node> $nodes
     * @param array<string, Edge> $edges
     * @param array<string,string> $eventOwners
     * @param array<string,string> $actionOwners
     */
    private function appendBootstrapRules(
        array &$nodes,
        array &$edges,
        string $domainId,
        DomainModuleInterface $module,
        array $eventOwners,
        array $actionOwners,
    ): void {
        if (!$module instanceof BootstrapRuleProvidingModuleInterface) {
            return;
        }

        foreach ($module->bootstrapRules() as $rule) {
            if (!$rule instanceof Rule) {
                continue;
            }

            $ruleId = 'rule:' . $module->name() . ':' . $rule->id;
            $actionType = is_string($rule->effect['action_type'] ?? null)
                ? trim((string) $rule->effect['action_type'])
                : '';
            $effectType = is_string($rule->effect['type'] ?? null)
                ? (string) $rule->effect['type']
                : '';

            $nodes[$ruleId] = new Node(
                $ruleId,
                ArchitectureGraphVocabulary::TYPE_RULE,
                $rule->name,
                metadata: [
                    'rule_id' => $rule->id,
                    'scope' => 'bootstrap_default',
                    'trigger' => $rule->trigger,
                    'version' => $rule->version,
                    'priority' => $rule->priority,
                    'conditions_count' => count($rule->conditions),
                    'effect_type' => $effectType,
                    'action_type' => $actionType,
                ],
            );
            $this->addEdge(
                $edges,
                $domainId,
                $ruleId,
                ArchitectureGraphVocabulary::REL_OWNS,
                ['source' => 'bootstrap.rules'],
            );

            $eventId = $this->ensureReferencedEvent($nodes, $rule->trigger, 'bootstrap.rule.trigger');
            $this->addEdge(
                $edges,
                $eventId,
                $ruleId,
                ArchitectureGraphVocabulary::REL_TRIGGERS,
                ['source' => 'bootstrap.rule.trigger'],
            );
            $this->appendDerivedDomainDependency(
                $edges,
                $domainId,
                $eventOwners[$rule->trigger] ?? null,
                'automation.rule.trigger',
                ['rule_id' => $rule->id, 'event_type' => $rule->trigger],
            );

            if ($actionType === '') {
                continue;
            }

            $actionId = $this->ensureReferencedAction($nodes, $actionType, 'bootstrap.rule.effect');
            $this->addEdge(
                $edges,
                $ruleId,
                $actionId,
                ArchitectureGraphVocabulary::REL_PRODUCES,
                [
                    'source' => 'bootstrap.rule.effect',
                    'effect_type' => $effectType,
                ],
            );
            $this->appendDerivedDomainDependency(
                $edges,
                $domainId,
                $actionOwners[$actionType] ?? null,
                'automation.rule.effect',
                ['rule_id' => $rule->id, 'action_type' => $actionType],
            );
        }
    }

    /** @param array<string, Node> $nodes @param array<string, Edge> $edges @param array<string,string> $actionOwners */
    private function appendBootstrapPolicies(
        array &$nodes,
        array &$edges,
        string $domainId,
        DomainModuleInterface $module,
        array $actionOwners,
    ): void {
        if (!$module instanceof BootstrapPolicyProvidingModuleInterface) {
            return;
        }

        foreach ($module->bootstrapPolicies() as $policy) {
            if (!$policy instanceof ActionPolicy) {
                continue;
            }

            $policyId = 'policy:' . $module->name() . ':' . $policy->id;
            $nodes[$policyId] = new Node(
                $policyId,
                ArchitectureGraphVocabulary::TYPE_POLICY,
                $policy->name ?? $policy->id,
                metadata: [
                    'policy_id' => $policy->id,
                    'scope' => 'bootstrap_default',
                    'action_type' => $policy->actionType,
                    'decision' => $policy->decision->value,
                    'priority' => $policy->priority,
                    'conditions_count' => count($policy->conditions),
                    'reason' => $policy->reason,
                ],
            );
            $this->addEdge(
                $edges,
                $domainId,
                $policyId,
                ArchitectureGraphVocabulary::REL_OWNS,
                ['source' => 'bootstrap.policies'],
            );

            $actionId = $this->ensureReferencedAction($nodes, $policy->actionType, 'bootstrap.policy.action');
            $this->addEdge(
                $edges,
                $policyId,
                $actionId,
                ArchitectureGraphVocabulary::REL_GOVERNS,
                [
                    'source' => 'bootstrap.policy.action',
                    'decision' => $policy->decision->value,
                ],
            );
            $this->appendDerivedDomainDependency(
                $edges,
                $domainId,
                $actionOwners[$policy->actionType] ?? null,
                'automation.policy',
                ['policy_id' => $policy->id, 'action_type' => $policy->actionType],
            );
        }
    }

    /** @param array<string, Node> $nodes */
    private function ensureReferencedEvent(array &$nodes, string $eventType, string $source): string
    {
        $nodeId = 'event:' . $eventType;
        $nodes[$nodeId] ??= new Node(
            $nodeId,
            ArchitectureGraphVocabulary::TYPE_EVENT,
            $eventType,
            metadata: [
                'event_type' => $eventType,
                'referenced_only' => true,
                'reference_source' => $source,
            ],
        );

        return $nodeId;
    }

    /** @param array<string, Node> $nodes */
    private function ensureReferencedAction(array &$nodes, string $actionType, string $source): string
    {
        $nodeId = 'action:' . $actionType;
        $nodes[$nodeId] ??= new Node(
            $nodeId,
            ArchitectureGraphVocabulary::TYPE_ACTION,
            $actionType,
            metadata: [
                'action_type' => $actionType,
                'referenced_only' => true,
                'reference_source' => $source,
            ],
        );

        return $nodeId;
    }

    /** @param array<string, Edge> $edges @param array<string,mixed> $metadata */
    private function appendDerivedDomainDependency(
        array &$edges,
        string $sourceDomainId,
        ?string $targetDomainId,
        string $source,
        array $metadata,
    ): void {
        if ($targetDomainId === null || $targetDomainId === $sourceDomainId) {
            return;
        }

        $this->addEdge(
            $edges,
            $sourceDomainId,
            $targetDomainId,
            ArchitectureGraphVocabulary::REL_DEPENDS_ON,
            [
                'source' => $source,
                'dependency_kind' => 'runtime_contract',
                ...$metadata,
            ],
        );
    }

    /** @param array<string, Edge> $edges @param array<string, mixed> $metadata */
    private function addEdge(array &$edges, string $source, string $target, string $relation, array $metadata = []): void
    {
        $id = 'edge:' . sha1($source . "\0" . $relation . "\0" . $target);
        if (isset($edges[$id])) {
            $existing = $edges[$id];
            $merged = $existing->metadata;
            if (($metadata['source'] ?? null) !== null && ($merged['source'] ?? null) !== ($metadata['source'] ?? null)) {
                $sources = array_values(array_unique(array_filter([
                    ...(array) ($merged['sources'] ?? []),
                    is_string($merged['source'] ?? null) ? $merged['source'] : null,
                    is_string($metadata['source'] ?? null) ? $metadata['source'] : null,
                ])));
                $merged['sources'] = $sources;
            }
            $edges[$id] = new Edge($id, $source, $target, $relation, [...$merged, ...$metadata]);
            return;
        }

        $edges[$id] = new Edge($id, $source, $target, $relation, $metadata);
    }

    private function domainId(string $moduleId): string
    {
        return 'domain:' . $moduleId;
    }
}
