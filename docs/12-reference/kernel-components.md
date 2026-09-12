---
title: Kernel Components Reference
description: Швидкий каталог основних COS Kernel components і відповідальностей.
status: active
updated: 2026-09-12
kind: reference
---

# Kernel Components Reference

Це короткий індекс, а не заміна коду або `docs/architecture/cos-kernel.md`.

Поточна executable версія Kernel: **`0.10.0`**.

| Area | Key components | Purpose |
| --- | --- | --- |
| Action | `Action`, `ActionProposal`, `ActionStatus`, `ExecutionResult`, `ActionService`, `ActionExecutor` | контрольована mutation lifecycle |
| Agent | `AgentDefinition`, `AgentInvocation`, `AgentExecution`, `AgentResult`, `AgentRuntime`, `StructuredAgentLlmClient` | structured decision runtime поверх governed LLM |
| Agent safety | `SensitiveContextRedactor`, `StructuredDecisionValidator`, `RoutedAgentContextBuilder` | context safety, validation, domain routing |
| Approval | `Approval`, `ApprovalStatus` + contracts/services | human decision gate |
| Audit | `AuditEntry` + repository contract | explanation trail |
| Event | `DomainEvent`, `EventMetadata`, `EventBus`, `OutboxMessage` | immutable facts and durable delivery |
| Policy | `ActionPolicy`, `PolicyDecision`, `PolicyEvaluation` | permission/risk gate |
| Queue | `Job` + contracts/handlers/services | durable asynchronous execution |
| Module | `DomainModuleInterface`, `DomainModuleRegistry` | Domain runtime contributions and ownership routing |
| Module lifecycle | `ModuleManifest`, `ModuleDiscovery`, `ModuleCatalog`, `ModuleInstallation`, `ModuleLifecycleManager`, `ActiveModuleResolver` | install/activate/deactivate module runtime |
| Module extensions | `ModuleContributions`, `ModuleExtensionContribution`, `ModuleExtensionRegistry` | module-owned API/config/UI/other extension surfaces |
| Module readiness | `ModuleReadinessDiagnostic` | installed/deployed/schema/dependency operational diagnostics |
| Capabilities | `ModuleCapabilityRegistry` | discoverable module features |
| Versioning | `KernelVersion`, `VersionConstraint` | module/kernel compatibility |
| Tenant | Kernel Tenant contracts/services | organization isolation |
| Transaction | Kernel transaction contract | transaction boundary without PDO dependency |
| Configuration | Kernel configuration contracts/services | validated module/runtime provisioning |
| Operations | worker/health contracts | operational runtime lifecycle |
| Observability | metric/logging contracts | technical telemetry |
| LLM request | `StructuredLlmRequest`, `StructuredLlmResponse` | provider-neutral structured inference contract |
| LLM routing | `LlmRoute`, `LlmRoutingPolicy`, `LlmProviderRegistry` | provider/model routing and lookup |
| LLM governance | `GovernedStructuredLlmClient`, `LlmGovernanceRepositoryInterface`, `LlmUsageRecord` | budgets, fallback, usage accounting, metrics |
| LLM failures | `LlmProviderException`, `LlmBudgetExceededException` | explicit provider/budget failure semantics |

## Ownership map

```text
Kernel/Action        owns execution mechanics
Domain/Automation    owns action meaning + handlers
Infrastructure       owns concrete external adapters

Kernel/Agent         owns safe invocation/decision mechanics
Domain/Automation    owns agent definition/context semantics
Kernel/Llm           owns provider-neutral governance mechanics
Infrastructure/Llm   owns provider implementation + persistence adapter

Kernel/Policy        owns evaluation mechanics
Domain/Automation    owns business policy catalog

Kernel/Event         owns event envelope/outbox mechanics
Domain               owns business event vocabulary

Kernel/Module        owns module lifecycle/registry/extension mechanics
Domain/module.php    owns module declarations/contributions
Consumer layer       owns concrete extension interface semantics
```

## Module extension shorthand

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

Built-in normalized points:

- `api.routes`;
- `tenant.configuration`.

Current generic point used by Web:

- `web.navigation`.

## LLM governance shorthand

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

Fallback дозволений лише для retryable `LlmProviderException` і configured next route.

## Runtime status shorthand

Документація повинна використовувати такі категорії:

- **implemented** — є production/runtime code path;
- **partial** — механізм є, але coverage/integration неповні;
- **target** — architecture rule або planned capability;
- **legacy** — попередня implementation, яку не слід використовувати як новий pattern.

## Safe extension checklist

Перед додаванням нового Kernel component перевірити:

1. Чи це generic mechanism для кількох Domains?
2. Чи не містить назва/logic business vocabulary конкретного Domain?
3. Чи можна responsibility реалізувати Domain contribution/extension замість зміни Kernel?
4. Чи contract не тягне Infrastructure dependency всередину Kernel?
5. Чи є lifecycle, idempotency, tenant isolation та audit implications?
6. Чи новий extension point має stable consumer contract, а не є випадковим callback?

Якщо відповідь на перше питання «ні», компонент майже напевно належить не в Kernel.
