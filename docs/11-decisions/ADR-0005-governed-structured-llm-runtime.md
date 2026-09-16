---
title: ADR-0005 — Structured LLM access проходить через централізований governance runtime
status: accepted
updated: 2026-09-16
kind: decision
---

# Контекст

COS має кілька LLM consumers: Agent runtime, Diagnostic AI та майбутні Domain-specific structured inference use cases. Якщо кожен Domain напряму вибирає provider/model, реалізує retry і рахує cost окремо, platform втрачає контроль над budgets, reliability та traceability.

Provider-neutral inference governance винесено в `Kernel\Llm` і є частиною поточного Kernel runtime.

# Рішення

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

Domain залишається власником prompt, response schema та business interpretation. Infrastructure володіє concrete HTTP/provider transport і secrets.

# Обґрунтування

Централізація потрібна для:

- tenant-level budget enforcement;
- consistent provider routing;
- controlled fallback;
- provider/model replacement без зміни Domain semantics;
- unified cost/token/latency telemetry;
- correlation між inference та business execution;
- централізованого застосування reliability та governance rules.

# Розглянуті альтернативи

## Direct provider client у кожному Domain

Відхилено: дублює retry/accounting/configuration і дозволяє Domain обходити platform policy.

## LLM abstraction лише всередині Agent runtime

Відхилено: не кожний structured LLM use case є Agent. Diagnostic AI є окремим Domain use case.

## Один globally hardcoded provider/model

Відхилено: не дає use-case routing, fallback та еволюції provider strategy.

# Наслідки

Позитивні:

- єдина governance point;
- tenant cost visibility;
- explicit reliability semantics;
- provider-neutral Domain/Agent code.

Вартість і обмеження:

- shared runtime стає critical dependency для LLM consumers;
- routing/budget configuration потребує operational discipline;
- budget control має чітко визначати, чи перевіряє accumulated spend, reservation або іншу модель accounting;
- provider outage має локалізуватися через routing/fallback rules, а не поширюватися як business decision.

# Правило fallback

Fallback дозволений лише для failure, класифікованого як retryable, і лише якщо routing policy має наступний route.

Configuration/model/schema errors не повинні тихо маскуватися іншим provider.

# Сумісність і міграція

Provider-specific gateways адаптуються до shared structured LLM boundary замість перенесення provider selection у Domain.

Agent runtime і Diagnostic AI використовують той самий governance layer, але зберігають різну Domain semantics та власні schemas/prompts.

# Перевірка

Перевіряються:

- budget denial before provider execution;
- explicit use-case route precedence;
- provider registry resolution;
- retryable/non-retryable fallback behavior;
- usage/cost/latency recording;
- propagation organization/use-case/correlation context;
- schema validation;
- відсутність provider secrets у Domain model.

# Пов’язані матеріали

- `docs/06-ai-agents/llm-governance.md`
- `docs/06-ai-agents/llm-boundary.md`
- `docs/06-ai-agents/agent-runtime.md`
- `app/Kernel/Llm/`
