<?php
declare(strict_types=1);

namespace Kernel\Module;

use InvalidArgumentException;
use Kernel\Action\Contract\ActionHandlerInterface;
use Kernel\Agent\AgentDefinition;
use Kernel\Agent\Contract\AgentContextBuilderInterface;
use Kernel\Module\Contract\ActionOwningModuleInterface;
use Kernel\Module\Contract\AgentProvidingModuleInterface;
use Kernel\Module\Contract\EventOwningModuleInterface;
use Kernel\Policy\Contract\PolicyContextProviderInterface;
use Kernel\Policy\Contract\PolicyContextProvidingModuleInterface;
use Kernel\Rule\Contract\RuleContextProviderInterface;
use RuntimeException;

final class DomainModuleRegistry
{
    /** @var array<string, DomainModuleInterface> */
    private array $modules = [];

    /** @var array<string, string> */
    private array $events = [];

    /** @var array<string, string> */
    private array $actions = [];

    /** @var array<string, AgentDefinition> */
    private array $agents = [];

    /** @var array<string, string> */
    private array $agentModules = [];

    /** @var array<string, AgentContextBuilderInterface> */
    private array $agentContexts = [];

    /** @var list<ActionHandlerInterface> */
    private array $handlers = [];

    /** @param iterable<DomainModuleInterface> $modules */
    public function __construct(iterable $modules)
    {
        foreach ($modules as $module) {
            $this->register($module);
        }
    }

    public function register(DomainModuleInterface $module): void
    {
        $name = $module->name();
        if (!preg_match('/^[a-z][a-z0-9_]*$/', $name)) {
            throw new InvalidArgumentException(sprintf('Invalid domain module name: %s.', $name));
        }
        if (isset($this->modules[$name])) {
            throw new InvalidArgumentException(sprintf('Domain module is already registered: %s.', $name));
        }

        if ($module instanceof EventOwningModuleInterface) {
            foreach ($module->eventTypes() as $eventType) {
                $this->claim($this->events, $eventType, $name, 'event');
            }
        }

        if ($module instanceof ActionOwningModuleInterface) {
            $handlers = $module->actionHandlers();
            foreach ($module->actionTypes() as $actionType) {
                $this->claim($this->actions, $actionType, $name, 'action');
                $supportingHandlers = array_filter(
                    $handlers,
                    static fn (ActionHandlerInterface $handler): bool => $handler->supports($actionType),
                );
                if (count($supportingHandlers) !== 1) {
                    throw new InvalidArgumentException(sprintf(
                        'Action %s must have exactly one handler; %d found.',
                        $actionType,
                        count($supportingHandlers),
                    ));
                }
            }
            array_push($this->handlers, ...$handlers);
        }

        if ($module instanceof AgentProvidingModuleInterface) {
            $contexts = $module->agentContextBuilders();
            foreach ($module->agents() as $agentName => $definition) {
                if ($agentName !== $definition->name) {
                    throw new InvalidArgumentException(sprintf('Agent registry key %s must equal definition name %s.', $agentName, $definition->name));
                }
                if (isset($this->agents[$agentName])) {
                    throw new InvalidArgumentException(sprintf('Agent is already registered: %s.', $agentName));
                }
                foreach ($definition->allowedActionTypes as $actionType) {
                    if (($this->actions[$actionType] ?? null) !== $name) {
                        throw new InvalidArgumentException(sprintf(
                            'Agent %s proposes action %s which is not owned by domain %s.',
                            $agentName,
                            $actionType,
                            $name,
                        ));
                    }
                }
                $context = $contexts[$agentName] ?? null;
                if (!$context instanceof AgentContextBuilderInterface) {
                    throw new InvalidArgumentException(sprintf('Agent %s has no context builder.', $agentName));
                }
                $this->agents[$agentName] = $definition;
                $this->agentModules[$agentName] = $name;
                $this->agentContexts[$agentName] = $context;
            }
        }

        $this->modules[$name] = $module;
    }

    /** @return list<DomainModuleInterface> */
    public function modules(): array
    {
        return array_values($this->modules);
    }

    /** @return list<ActionHandlerInterface> */
    public function actionHandlers(): array
    {
        return $this->handlers;
    }

    public function agent(string $name): AgentDefinition
    {
        return $this->agents[$name] ?? throw new RuntimeException(sprintf('Unknown agent: %s.', $name));
    }

    public function agentContextBuilder(string $name): AgentContextBuilderInterface
    {
        return $this->agentContexts[$name] ?? throw new RuntimeException(sprintf('No context builder for agent: %s.', $name));
    }

    public function ruleContextProviderFor(string $eventType): RuleContextProviderInterface
    {
        $moduleName = $this->events[$eventType] ?? null;
        $module = is_string($moduleName) ? ($this->modules[$moduleName] ?? null) : null;
        return $module instanceof EventOwningModuleInterface
            ? $module->ruleContextProvider()
            : throw new RuntimeException(sprintf('No rule context provider for event: %s.', $eventType));
    }

    public function policyContextProviderFor(string $actionType): ?PolicyContextProviderInterface
    {
        $moduleName = $this->actions[$actionType] ?? null;
        $module = is_string($moduleName) ? ($this->modules[$moduleName] ?? null) : null;
        return $module instanceof PolicyContextProvidingModuleInterface
            ? $module->policyContextProvider()
            : null;
    }

    /** @return list<string> */
    public function actionTypesForDomain(string $domainName): array
    {
        return array_values(array_keys(array_filter(
            $this->actions,
            static fn (string $owner): bool => $owner === $domainName,
        )));
    }

    public function ownerOfEvent(string $eventType): ?string
    {
        return $this->events[$eventType] ?? null;
    }

    public function ownerOfAction(string $actionType): ?string
    {
        return $this->actions[$actionType] ?? null;
    }

    public function ownerOfAgent(string $agentName): ?string
    {
        return $this->agentModules[$agentName] ?? null;
    }

    public function ownsEvent(string $domainName, string $eventType): bool
    {
        return $this->ownerOfEvent($eventType) === $domainName;
    }

    public function ownsAction(string $domainName, string $actionType): bool
    {
        return $this->ownerOfAction($actionType) === $domainName;
    }

    public function hasAgent(string $agentName): bool
    {
        return isset($this->agents[$agentName]);
    }

    /** @param array<string, string> $registry */
    private function claim(array &$registry, string $type, string $module, string $kind): void
    {
        if ($type === '') {
            throw new InvalidArgumentException(sprintf('Domain %s contains an empty %s type.', $module, $kind));
        }
        if (isset($registry[$type])) {
            throw new InvalidArgumentException(sprintf('%s type %s is already owned by %s.', ucfirst($kind), $type, $registry[$type]));
        }
        $registry[$type] = $module;
    }
}
