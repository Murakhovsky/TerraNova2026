---
title: Компоненти Kernel
description: Швидкий каталог основних COS Kernel components і відповідальностей.
status: active
updated: 2026-09-16
kind: reference
---

# Компоненти Kernel

Це короткий навігаційний довідник. Exact classes і contracts визначає поточний код.

Поточна executable версія Kernel: **`0.11.9`**.

| Область | Ключові компоненти | Призначення |
| --- | --- | --- |
| Action | `Action`, `ActionProposal`, `ActionStatus`, `ExecutionResult`, `ActionService`, `ActionExecutor` | контрольований mutation lifecycle |
| Agent | `AgentDefinition`, `AgentInvocation`, `AgentExecution`, `AgentResult`, `AgentRuntime`, `StructuredAgentLlmClient` | structured decision runtime поверх governed LLM |
| Agent safety | `SensitiveContextRedactor`, `StructuredDecisionValidator`, `RoutedAgentContextBuilder` | context safety, validation, Domain routing |
| Approval | `Approval`, `ApprovalStatus` + contracts/services | human decision gate |
| Audit | `AuditEntry` + repository contract | explanation trail |
| Event | `DomainEvent`, `EventMetadata`, `EventBus`, `OutboxMessage` | immutable facts і durable delivery |
| Policy | `ActionPolicy`, `PolicyDecision`, `PolicyEvaluation` | permission/risk gate |
| Queue | `Job` + contracts/handlers/services | durable asynchronous execution |
| Execution runtime | `RuleEngineEventHandler`, `QueuedActionProposalSink`, `ActionPolicyService`, `AgentRunJobHandler`, `ActionExecutionJobHandler`, `WorkerSupervisor` | decision → policy → queue → execution lifecycle |
| Process | `ProcessDefinition`, `ProcessStep`, `ProcessEdge`, `RuntimeMapping`, `ProcessRegistryInterface` | універсальна структура process topology без Domain-specific semantics |
| Module | `DomainModuleInterface`, `DomainModuleRegistry` | Domain runtime contributions і ownership routing |
| Module lifecycle | `ModuleManifest`, `ModuleDiscovery`, `ModuleCatalog`, `ModuleInstallation`, `ModuleLifecycleManager`, `ActiveModuleResolver` | install/activate/deactivate module runtime |
| Module extensions | `ModuleContributions`, `ModuleExtensionContribution`, `ModuleExtensionRegistry` | module-owned shared extension surfaces |
| Cross-domain contracts | `CrossDomainContract` + `ModuleContributions.cross_domain_contracts` | declared Domain-to-Domain boundaries з `requires`/`provides` semantics |
| Module readiness | `ModuleReadinessDiagnostic` | installed/deployed/schema/dependency operational diagnostics |
| Capabilities | `ModuleCapabilityRegistry` | discoverable module capabilities |
| Versioning | `KernelVersion`, `VersionConstraint` | module/Kernel compatibility |
| Tenant | Kernel Tenant contracts/services | organization isolation |
| Transaction | Kernel transaction contract | transaction boundary без PDO dependency |
| Configuration | Kernel configuration contracts/services | validated module/runtime provisioning |
| Operations | worker/health contracts | operational runtime lifecycle |
| Observability | metric/logging contracts | technical telemetry |
| LLM request | `StructuredLlmRequest`, `StructuredLlmResponse` | provider-neutral structured inference contract |
| LLM routing | `LlmRoute`, `LlmRoutingPolicy`, `LlmProviderRegistry` | provider/model routing і lookup |
| LLM governance | `GovernedStructuredLlmClient`, `LlmGovernanceRepositoryInterface`, `LlmUsageRecord` | budgets, fallback, usage accounting, metrics |
| LLM failures | `LlmProviderException`, `LlmBudgetExceededException` | explicit provider/budget failure semantics |

## Карта ownership

```text
Kernel/Action        owns execution mechanics
Domain/Automation    owns Action meaning + handlers
Infrastructure       owns concrete external adapters

Kernel/Agent         owns safe invocation/decision mechanics
Domain/Automation    owns Agent definition/context semantics
Kernel/Llm           owns provider-neutral governance mechanics
Infrastructure/Llm   owns provider implementation + persistence adapter

Kernel/Policy        owns evaluation mechanics
Domain/Automation    owns business Policy catalog

Kernel/Event         owns event envelope/outbox mechanics
Domain               owns business Event vocabulary

Kernel/Process       owns process structure/invariants/contracts
resources/processes  owns canonical business-process definitions
Domain/module.php    owns capabilities + cross-domain contract declarations

Kernel/Module        owns module lifecycle/registry/extension mechanics
Domain/module.php    owns module declarations/contributions
Consumer layer       owns concrete extension interface semantics
```

## Коротка схема execution runtime

```text
Event
  ↓
Rule / Agent
  ↓
ActionProposal
  ↓
Policy
  ↓
Approval (when required)
  ↓
Queue
  ↓
ActionExecutionJobHandler
  ↓
ActionService / ActionExecutor
```

Rule та Agent не виконують side effects напряму. Вони створюють proposal, який проходить authority gate та durable execution path.

## Коротка схема Process

```text
resources/processes/*.json
        ↓
JsonProcessRegistry
        ↓
Kernel\Process model
        ↓
Process consumers
        ├─ Documentation / ProcessDiagram
        ├─ Visualization
        ├─ Diagnostics
        └─ future runtime consumers
```

Process V0.1 не є execution engine. Kernel перевіряє structural invariants, а capabilities та cross-domain evidence звіряються module/evidence layer.

## Коротка схема module extensions

```text
module.php
  ↓
ModuleContributions
  ↓
ModuleExtensionRegistry
  ↓
consumer resolves service
  ↓
active-module check where required
```

Поточні extension points:

- `api.routes`;
- `tenant.configuration`;
- `event.consumers`;
- `web.navigation`.

Cross-domain contracts не є extension points і мають окрему typed model.

## Коротка схема LLM governance

```text
StructuredLlmRequest
  ↓
Budget
  ↓
RoutingPolicy
  ↓
ProviderRegistry
  ↓
Provider
  ↓
UsageRecord + Metrics
```

Fallback дозволений лише для retryable provider failure і configured next route.

## Категорії стану

Для документації використовуйте:

- **implemented** — є production/runtime code path;
- **partial** — механізм є, але coverage/integration неповні;
- **target** — architecture rule або planned capability;
- **legacy** — попередня implementation, яку не слід використовувати як новий pattern.

## Перевірка нового Kernel component

Перед додаванням компонента перевірте:

1. Чи це generic mechanism для кількох Domains?
2. Чи назва/logic не містить business vocabulary конкретного Domain?
3. Чи responsibility не можна реалізувати Domain contribution/contract замість зміни Kernel?
4. Чи contract не тягне concrete Infrastructure dependency у Kernel?
5. Чи враховані lifecycle, idempotency, tenant isolation та audit implications?
6. Чи новий extension point має stable consumer contract, а не є випадковим callback?
7. Якщо це Process concern, чи Kernel справді має знати лише structure, а не Domain semantics?

Якщо на перше питання відповідь «ні», компонент майже напевно належить не в Kernel.
