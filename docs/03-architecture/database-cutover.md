---
title: Перехід operational database
description: Поступове перенесення COS з legacy MySQL до єдиної canonical Symfony/COS MySQL schema.
status: active
updated: 2026-09-20
kind: architecture
contract: architecture-v1
---

# Перехід operational database

Symfony runtime уже став канонічним, але на старті цього етапу 86 сервісів у composition root напряму використовували `legacy_cos.pdo`. Це означає, що legacy MySQL фактично залишався operational source of truth.

Ціль не змінювати MySQL. Ціль — одна canonical MySQL schema, керована Doctrine migrations, при цьому стабільні MySQL repositories не переписуються лише заради іншого persistence API.

## Канонічне підключення

`cos.database.pdo` отримує native PDO з тієї самої Doctrine DBAL connection, що використовує `DATABASE_URL`.

```text
DATABASE_URL
    ↓
Doctrine DBAL
    ↓
cos.database.pdo
    ↓
existing MySQL repositories
```

Нові залежності від `legacy_cos.pdo` забороняє architecture gate. Початковий список заморожений і може лише скорочуватися.

## Порядок хвиль

Міграція йде transactional table clusters, а не за назвами framework-класів:

1. module/runtime та незалежні Platform stores — Wave 0 module runtime завершено; Wave 1 operational metrics/resilience/LLM governance переведено на canonical MySQL;
2. Identity/Tenant і configuration/integration credentials;
3. Diagnostic;
4. Documents, Service, RealEstate;
5. Property canonical asset/inventory/listing та видалення compatibility projections;
6. Sales + CRM integrations;
7. Kernel Event/Rule/Policy/Action/Audit/Queue і фінальний global transaction boundary.

Для write use case не можна перемкнути лише repository, якщо TransactionManager/EventBus залишаються на іншій фізичній базі. Усі учасники однієї atomic transaction мають переходити разом.

## Критерій завершення

Cutover завершений лише коли `legacy_cos.pdo` зникає з service graph, deploy більше не створює legacy DML user, Symfony containers не потребують legacy database network, усі canonical tables створюються Doctrine migrations, а legacy MySQL можна зупинити без деградації HTTP, workers, scheduler, integrations чи agent runtime.


## Хвиля 1 — операційні сховища Platform

Другий cutover cluster переносить незалежні operational stores:

- `cos_external_circuits`;
- `cos_operational_metrics`;
- `cos_llm_budgets`;
- `cos_llm_usage`;
- `cos_llm_budget_reservations`.

Runtime owners після cutover використовують `cos.database.pdo`:

- `MysqlCircuitBreakerStore`;
- `MysqlMetricsRecorder`;
- `MysqlLlmGovernanceRepository`.

LLM budgets, usage, active reservations і circuit state переходять разом, тому connection-local `GET_LOCK` та LLM budget transactions не розриваються між двома фізичними MySQL. Cutover journal id: `platform-operations-v1`.
