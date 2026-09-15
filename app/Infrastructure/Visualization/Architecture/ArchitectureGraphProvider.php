<?php
declare(strict_types=1);

namespace Infrastructure\Visualization\Architecture;

use Kernel\Module\Contract\ActionOwningModuleInterface;
use Kernel\Module\Contract\AgentProvidingModuleInterface;
use Kernel\Module\Contract\EventOwningModuleInterface;
use Kernel\Module\DomainModuleInterface;
use Kernel\Module\DomainModuleRegistry;
use Kernel\Module\ModuleCatalog;
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
            $this->addEdge($edges, $kernelId, $domainId, ArchitectureGraphVocabulary::REL_CONTAINS);

            foreach ($manifest->dependencies as $dependency) {
                $this->addEdge(
                    $edges,
                    $domainId,
                    $this->domainId($dependency),
                    ArchitectureGraphVocabulary::REL_DEPENDS_ON,
                    ['constraint' => $manifest->constraintFor($dependency)],
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
                $this->addEdge($edges, $domainId, $capabilityId, ArchitectureGraphVocabulary::REL_OWNS);
            }

            foreach ($definition->contributions->allServiceIds() as $serviceId) {
                $nodeId = 'service:' . $serviceId;
                $nodes[$nodeId] ??= new Node(
                    $nodeId,
                    ArchitectureGraphVocabulary::TYPE_SERVICE,
                    $serviceId,
                    metadata: ['service_id' => $serviceId],
                );
                $this->addEdge($edges, $domainId, $nodeId, ArchitectureGraphVocabulary::REL_CONTRIBUTES);
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
                    $this->addEdge($edges, $serviceNodeId, $extensionId, ArchitectureGraphVocabulary::REL_CONTRIBUTES_TO);
                }
            }
        }

        foreach ($this->runtimeModules->modules() as $module) {
            $domainId = $this->domainId($module->name());
            if (!isset($nodes[$domainId])) {
                $nodes[$domainId] = new Node(
                    $domainId,
                    ArchitectureGraphVocabulary::TYPE_DOMAIN,
                    ucfirst($module->name()),
                    metadata: ['module_id' => $module->name(), 'runtime_only' => true],
                );
                $this->addEdge($edges, $kernelId, $domainId, ArchitectureGraphVocabulary::REL_CONTAINS);
            }

            $this->appendRuntimeEvents($nodes, $edges, $domainId, $module);
            $this->appendRuntimeActions($nodes, $edges, $domainId, $module);
            $this->appendRuntimeAgents($nodes, $edges, $domainId, $module);
        }

        return new Graph(array_values($nodes), array_values($edges));
    }

    /** @param array<string, Node> $nodes @param array<string, Edge> $edges */
    private function appendRuntimeEvents(array &$nodes, array &$edges, string $domainId, DomainModuleInterface $module): void
    {
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
            $this->addEdge($edges, $domainId, $nodeId, ArchitectureGraphVocabulary::REL_OWNS);
        }
    }

    /** @param array<string, Node> $nodes @param array<string, Edge> $edges */
    private function appendRuntimeActions(array &$nodes, array &$edges, string $domainId, DomainModuleInterface $module): void
    {
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
            $this->addEdge($edges, $domainId, $nodeId, ArchitectureGraphVocabulary::REL_OWNS);

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
                $this->addEdge($edges, $nodeId, $handlerId, ArchitectureGraphVocabulary::REL_HANDLED_BY);
            }
        }
    }

    /** @param array<string, Node> $nodes @param array<string, Edge> $edges */
    private function appendRuntimeAgents(array &$nodes, array &$edges, string $domainId, DomainModuleInterface $module): void
    {
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
            $this->addEdge($edges, $domainId, $nodeId, ArchitectureGraphVocabulary::REL_OWNS);

            foreach ($definition->allowedActionTypes as $actionType) {
                $this->addEdge(
                    $edges,
                    $nodeId,
                    'action:' . $actionType,
                    ArchitectureGraphVocabulary::REL_PROPOSES,
                );
            }
        }
    }

    /** @param array<string, Edge> $edges @param array<string, mixed> $metadata */
    private function addEdge(array &$edges, string $source, string $target, string $relation, array $metadata = []): void
    {
        $id = 'edge:' . sha1($source . "\0" . $relation . "\0" . $target);
        $edges[$id] ??= new Edge($id, $source, $target, $relation, $metadata);
    }

    private function domainId(string $moduleId): string
    {
        return 'domain:' . $moduleId;
    }
}
