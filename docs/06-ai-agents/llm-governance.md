---
title: Керування LLM
description: Маршрутизація, fallback провайдерів, бюджети tenant, облік використання та telemetry для структурованого LLM runtime.
status: active
updated: 2026-09-16
kind: architecture
---

# Керування LLM

COS використовує спільний керований runtime для структурованих викликів LLM. Domains і Agents не повинні самостійно вирішувати, до якого provider звертатися, скільки можна витрачати і як обліковувати usage.

## Потік виконання

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

| Компонент | Відповідальність |
| --- | --- |
| `StructuredLlmRequest` | незалежний від provider структурований request + governance context |
| `LlmRoute` | пара provider + model |
| `LlmRoutingPolicy` | вибір default/use-case routes |
| `LlmProviderRegistry` | доступ до зареєстрованих provider clients |
| `GovernedStructuredLlmClient` | budget, routing, fallback, accounting, metrics |
| `LlmProviderException` | помилка provider + retryable classification |
| `LlmBudgetExceededException` | явна відмова через бюджет |
| `LlmGovernanceRepositoryInterface` | контракт збереження budget/spend та usage |
| `LlmUsageRecord` | запис usage/cost/latency/fallback |

Конкретний HTTP provider client залишається в `app/Infrastructure/Llm`.

## Контекст запиту

Керований request може переносити:

- `organizationId`;
- `useCase`;
- `correlationId`;
- model hint;
- system/user prompts;
- response schema;
- output-token limit.

Так бізнесовий контекст не змішується з transport details конкретного provider.

## Політика маршрутизації

`LlmRoutingPolicy` містить:

- обов’язковий список default routes;
- необов’язкові route lists для конкретних `useCase`.

Правило пріоритету:

```text
explicit use-case policy
    >
domain/request model hint
    >
default route model
```

Якщо для `useCase` є явний routing, model hint на рівні request його не переписує.

Це важливо для централізованого керування вартістю й надійністю: Domain не повинен обходити platform policy через стару назву model у власній definition.

## Реєстр провайдерів

`LlmProviderRegistry` містить конкретні clients за `provider id`. Routing policy посилається на provider id, а не на HTTP endpoint.

```text
use case
   ↓
route: provider=model
   ↓
registry
   ↓
provider client
```

Транспорт провайдера можна замінити без зміни Domain/Agent semantics.

## Семантика резервного маршруту

Fallback відбувається лише якщо `LlmProviderException` класифікований як `retryable` і в routing policy є наступний route.

Типові retryable failures:

- transport failure / status `0`;
- HTTP `429`;
- HTTP `5xx`;
- open circuit breaker.

Configuration або model errors є non-retryable.

> Fallback є механізмом надійності, а не способом приховати неправильну конфігурацію.

## Бюджети tenant

Перед provider call `GovernedStructuredLlmClient` перевіряє місячний budget для `organizationId` і configured currency.

Поточна логіка:

```text
monthlySpend >= monthlyBudget
    → deny before request
```

Якщо budget не заданий або `organizationId` відсутній, budget gate не блокує call.

Важливе обмеження: runtime перевіряє вже накопичений spend, але не резервує наперед невідому вартість поточного request. Це не hard pre-paid quota.

## Облік використання

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

Це platform accounting, а не бізнес-стан Domain.

## Метрики

Runtime записує, зокрема:

```text
llm.request.error
llm.budget.denied
llm.request.latency_ms
llm.request.fallback_count
llm.request.input_tokens
llm.request.output_tokens
llm.request.cost
```

Labels включають provider/model/use case там, де вони доступні, а tenant scope передається через metrics contract.

## Конфігурація

Bootstrap підтримує, зокрема:

- primary provider/model із базової LLM config;
- `LLM_FALLBACK_ENDPOINT`;
- `LLM_FALLBACK_PROVIDER`;
- `LLM_FALLBACK_MODEL`;
- `LLM_ROUTES_JSON` для use-case routing;
- `LLM_BUDGET_CURRENCY`.

Secrets і tokens залишаються відповідальністю Infrastructure/config і не повинні потрапляти в Domain definitions чи приклади документації.

## Agent і Diagnostic

Спільний LLM governance не означає, що всі LLM calls стали Agents.

```text
Kernel/Llm     = provider-neutral governed inference
Kernel/Agent   = controlled decision runtime producing proposals
Diagnostic AI  = domain use case using structured LLM boundary
```

Agent runtime використовує `StructuredAgentLlmClient`. Diagnostic AI передає `organizationId`, свій `useCase` і `correlationId` у той самий керований LLM runtime.

## Інваріанти

1. Domain не вибирає конкретний provider transport.
2. Явна platform routing policy має пріоритет над model hint.
3. Budget check відбувається до provider execution.
4. Fallback дозволений лише для retryable provider failure.
5. Usage, cost і latency мають бути прив’язані до organization/use case, якщо context доступний.
6. LLM response сам по собі не отримує mutation authority.
7. Provider secrets не потрапляють у Domain model або persisted decision context.

## Карта коду

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
