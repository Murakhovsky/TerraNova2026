---
title: LLM Governance
description: Routing, provider fallback, tenant budgets, usage accounting і telemetry для structured LLM runtime.
status: active
updated: 2026-09-12
kind: architecture
---

# LLM Governance

Kernel V0.10 виніс structured LLM access у shared governed runtime. Мета — щоб Domains і Agents не вирішували самі, до якого provider ходити, скільки можна витрачати і як рахувати usage.

## Runtime flow

```text
StructuredLlmRequest
    ↓
monthly tenant budget check
    ↓
LlmRoutingPolicy
    ↓
ordered LlmRoute list
    ↓
LlmProviderRegistry
    ↓
provider client
    ├─ success → usage record + metrics
    ├─ retryable error → next configured route
    └─ non-retryable error → fail
```

## Основні компоненти

| Component | Responsibility |
| --- | --- |
| `StructuredLlmRequest` | provider-neutral structured request + governance context |
| `LlmRoute` | provider + model pair |
| `LlmRoutingPolicy` | default/use-case route selection |
| `LlmProviderRegistry` | доступ до registered provider clients |
| `GovernedStructuredLlmClient` | budget, routing, fallback, accounting, metrics |
| `LlmProviderException` | provider error + retryable classification |
| `LlmBudgetExceededException` | explicit budget denial |
| `LlmGovernanceRepositoryInterface` | monthly budget/spend + usage persistence contract |
| `LlmUsageRecord` | persisted usage/cost/latency/fallback record |

Concrete HTTP provider client залишається в `app/Infrastructure/Llm`.

## Request context

Governed request може переносити:

- `organizationId`;
- `useCase`;
- `correlationId`;
- model hint;
- system/user prompts;
- response schema;
- output-token limit.

Це дозволяє не змішувати business context із provider transport details.

## Routing policy

`LlmRoutingPolicy` має:

- обов'язковий список default routes;
- optional route lists для конкретних `useCase`.

Правило пріоритету:

```text
explicit use-case policy
    >
domain/request model hint
    >
default route model
```

Якщо для `useCase` є explicit routing, request-level model hint його не переписує.

Це важливо для централізованого cost/reliability governance: Domain не може випадково обійти platform policy лише тому, що в definition залишився старий model name.

## Provider registry

`LlmProviderRegistry` містить concrete clients за provider id. Routing policy посилається на provider id, а не на HTTP endpoint.

```text
use case
   ↓
route: provider=model
   ↓
registry
   ↓
provider client
```

Provider transport можна замінити без зміни Domain/Agent semantics.

## Fallback semantics

Fallback відбувається тільки якщо `LlmProviderException` класифікований як `retryable` і в routing policy є наступний route.

Типові retryable failures у HTTP adapter:

- transport failure / status `0`;
- HTTP `429`;
- HTTP `5xx`;
- open circuit breaker.

Configuration/model errors є non-retryable.

Принцип:

> fallback — це reliability mechanism, а не спосіб приховати неправильну конфігурацію.

## Tenant budgets

Перед provider call `GovernedStructuredLlmClient` перевіряє monthly budget для `organizationId` і configured currency.

Поточна AS-IS логіка:

```text
monthlySpend >= monthlyBudget
    → deny before request
```

Якщо budget не заданий або `organizationId` відсутній, budget gate не блокує call.

Важливе обмеження AS-IS: runtime перевіряє вже накопичений spend, але не резервує наперед невідому вартість поточного request. Це не треба описувати як hard pre-paid quota.

## Usage accounting

Після успішного call записується `LlmUsageRecord` з доступними даними:

- organization;
- correlation id;
- use case;
- provider;
- model;
- input tokens;
- output tokens;
- cost amount/currency;
- latency;
- fallback count.

Це platform accounting, не Domain business state.

## Metrics

Поточний runtime пише, зокрема:

```text
llm.request.error
llm.budget.denied
llm.request.latency_ms
llm.request.fallback_count
llm.request.input_tokens
llm.request.output_tokens
llm.request.cost
```

Labels включають provider/model/use case там, де вони доступні, та tenant scope через metrics contract.

## Configuration surface

Поточний bootstrap підтримує:

- primary provider/model із base LLM config;
- `LLM_FALLBACK_ENDPOINT`;
- `LLM_FALLBACK_PROVIDER`;
- `LLM_FALLBACK_MODEL`;
- `LLM_ROUTES_JSON` для use-case routing;
- `LLM_BUDGET_CURRENCY`.

Secrets/tokens залишаються Infrastructure/config concern і не повинні потрапляти в Domain definitions або documentation examples.

## Agent і Diagnostic

Shared LLM governance не означає, що всі LLM calls стали Agents.

```text
Kernel/Llm     = provider-neutral governed inference
Kernel/Agent   = controlled decision runtime producing proposals
Diagnostic AI  = domain use case using structured LLM boundary
```

Agent runtime використовує adapter `StructuredAgentLlmClient`. Diagnostic AI передає `organizationId`, diagnostic use case і correlation id у той самий governed structured LLM runtime.

## Invariants

1. Domain не вибирає concrete provider transport.
2. Explicit platform routing policy має пріоритет над model hint.
3. Budget check відбувається до provider execution.
4. Fallback можливий лише для retryable provider failure.
5. Usage/cost/latency мають бути traceable до organization/use case, якщо context доступний.
6. LLM response сам по собі не отримує mutation authority.
7. Provider secrets не потрапляють у Domain model або persisted decision context.

## Code map

```text
app/Kernel/Llm/
  StructuredLlmRequest.php
  StructuredLlmResponse.php
  LlmRoute.php
  LlmRoutingPolicy.php
  LlmProviderRegistry.php
  LlmProviderException.php
  LlmBudgetExceededException.php
  LlmGovernanceRepositoryInterface.php
  LlmUsageRecord.php
  GovernedStructuredLlmClient.php

app/Infrastructure/Llm/
  HttpStructuredLlmClient.php
  MysqlLlmGovernanceRepository.php

app/Kernel/Agent/Service/StructuredAgentLlmClient.php
app/Domains/Diagnostic/Infrastructure/AI/OpenAiGateway.php
```
