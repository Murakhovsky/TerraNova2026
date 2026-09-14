---
title: ADR-0005 — Structured LLM access uses centralized governance runtime
status: accepted
updated: 2026-09-12
kind: decision
---

# Context

COS має кілька LLM consumers: Agent runtime, Diagnostic AI та майбутні domain-specific structured inference use cases. Якщо кожен Domain напряму вибирає provider/model, реалізує retry і рахує cost окремо, platform втрачає контроль над бюджетами, reliability та traceability.

Kernel V0.10 виніс provider-neutral inference governance у `Kernel\\Llm`.

# Decision

**Structured LLM calls проходять через centralized governed runtime.**

Канонічний flow:

```text
Domain / Agent use case
→ StructuredLlmRequest
→ organization budget check
→ LlmRoutingPolicy
→ LlmProviderRegistry
→ provider call
→ retryable fallback when configured
→ usage accounting + metrics
→ StructuredLlmResponse
```

Governance context може містити:

- `organizationId`;
- `useCase`;
- `correlationId`;
- model hint;
- token/output constraints.

Domain залишається власником prompt, response schema та business interpretation. Infrastructure залишається власником concrete HTTP/provider transport і secrets.

# Rationale

Централізація потрібна для:

- tenant-level budget enforcement;
- consistent provider routing;
- controlled fallback;
- provider/model replacement без зміни Domain semantics;
- unified cost/token/latency telemetry;
- correlation між inference та business execution.

# Alternatives considered

## Direct provider client у кожному Domain

Відхилено: дублює retry/accounting/configuration і дозволяє Domain обходити platform policy.

## LLM abstraction тільки всередині Agent runtime

Відхилено: не кожний structured LLM use case є Agent. Diagnostic AI є окремим domain use case.

## Один глобально hardcoded provider/model

Відхилено: не дає use-case routing, fallback та еволюції provider strategy.

# Consequences

Позитивні:

- єдиний governance point;
- tenant cost visibility;
- explicit reliability semantics;
- provider-neutral Domain/Agent code.

Вартість/обмеження:

- shared runtime стає критичним dependency для LLM consumers;
- routing/budget configuration потребує operational discipline;
- current budget check базується на already accumulated spend і не є hard pre-paid reservation поточного request.

# Fallback rule

Fallback дозволений лише для failure, явно класифікованого як retryable, і лише якщо routing policy має наступний route.

Configuration/model errors не повинні тихо маскуватися іншим provider.

# Compatibility / Migration

Existing provider-specific gateways мають адаптуватися до `StructuredLlmClientInterface`/governed client, а не переносити provider selection у Domain.

Agent runtime використовує adapter до shared structured LLM boundary; Diagnostic AI використовує ту саму governance layer без перетворення Diagnostic use case на Agent.

# Verification

Перевіряються:

- budget denial before provider execution;
- explicit use-case route precedence;
- provider registry resolution;
- retryable/non-retryable fallback behavior;
- usage/cost/latency recording;
- propagation organization/use-case/correlation context;
- відсутність provider secrets у Domain model.

# Related

- `docs/06-ai-agents/llm-governance.md`
- `docs/06-ai-agents/agent-runtime.md`
- `app/Kernel/Llm/`
