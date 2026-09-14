---
title: ADR-0001 — Kernel owns mechanisms, Domains own business semantics
status: accepted
updated: 2026-09-12
kind: decision
---

# Context

COS є modular monolith з generic execution runtime та окремими bounded contexts. Без чіткої межі shared layer швидко починає накопичувати Sales/Property/Diagnostic vocabulary, а Domains перетворюються на декоративні папки навколо централізованого service layer.

Цей ADR формалізує вже чинну AS-IS dependency model.

# Decision

**Kernel володіє універсальними механізмами виконання. Domain володіє бізнес-семантикою.**

Kernel може знати про:

- Event / Outbox;
- Rule;
- Agent runtime;
- Action lifecycle;
- Policy;
- Approval;
- Queue;
- Audit;
- Transaction abstraction;
- Tenant context;
- Configuration;
- Module lifecycle/extensions;
- Operations / Observability;
- provider-neutral LLM governance.

Kernel не визначає, що таке qualified lead, property moderation, diagnostic finding або інший бізнес-термін.

Domain володіє:

- entities/value objects/invariants;
- use cases;
- business Events;
- domain Rules/Agents/Actions/Policies;
- outbound ports;
- власною persistence semantics;
- module contributions.

Dependency direction:

```text
Kernel         -> PHP/core contracts only
Domain         -> Kernel + same Domain
Infrastructure -> Kernel/Domain contracts
Interfaces     -> exposed Application/Kernel services
Bootstrap      -> assembles concrete dependencies
```

# Rationale

Ця межа дозволяє:

- додавати нові Domains без модифікації Kernel business logic;
- тестувати runtime mechanisms незалежно від конкретної вертикалі;
- не прив'язувати business core до Phalcon/PDO/provider SDK;
- міняти adapters без переписування Domain rules;
- зберігати один execution model для Web/API/CLI/Telegram/workers.

# Alternatives considered

## Fat shared service layer

Відхилено: shared services неминуче накопичують business vocabulary і створюють приховані cross-domain dependencies.

## Kernel with domain-specific branches

Наприклад `if sales`, `if property`. Відхилено: кожен новий Domain вимагав би змін Kernel.

## Framework modules as business architecture

Відхилено: delivery/framework boundary не повинен визначати bounded contexts.

# Consequences

Позитивні:

- сильні dependency boundaries;
- локальне ownership бізнес-правил;
- replaceable infrastructure;
- стабільний generic runtime.

Негативні/вартість:

- більше explicit ports/contracts;
- Bootstrap/composition стає важливішим;
- іноді потрібні окремі Domain adapters замість «одного універсального сервісу».

# Compatibility / Migration

Sales є reference implementation. Інші Domains поступово доводяться до того самого рівня module/runtime contract без вимоги одномоментного rewrite.

Legacy framework/persistence surfaces залишаються compatibility boundaries, а не новою точкою розширення.

# Verification

Архітектурні guards повинні блокувати:

- Kernel -> Domains/Infrastructure/Interfaces/Phalcon/PDO;
- Domain Application/Model -> Infrastructure/Interfaces/Phalcon/PDO;
- повернення business logic у controllers.

Основний executable guard: `tests/architecture/layer_dependencies.php`.

# Related

- `docs/architecture/cos-kernel.md`
- `docs/03-architecture/domain-map.md`
- `docs/09-development/adding-a-domain.md`
