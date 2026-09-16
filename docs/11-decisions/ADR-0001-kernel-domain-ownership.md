---
title: ADR-0001 — Kernel володіє механізмами, Domains володіють бізнес-семантикою
status: accepted
updated: 2026-09-16
kind: decision
---

# Контекст

COS є modular monolith із generic execution runtime та окремими bounded contexts. Без чіткої межі shared layer швидко починає накопичувати Sales/Property/Diagnostic vocabulary, а Domains перетворюються на декоративні папки навколо централізованого service layer.

Цей ADR формалізує чинну AS-IS dependency model.

# Рішення

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
- transaction abstraction;
- tenant context;
- configuration;
- module lifecycle/extensions;
- operations / observability;
- provider-neutral LLM governance;
- Process structure та registry contracts без бізнес-семантики конкретного Domain.

Kernel не визначає, що таке qualified lead, property moderation, diagnostic finding або інший бізнес-термін.

Domain володіє:

- entities/value objects/invariants;
- use cases;
- business Events;
- domain Rules/Agents/Actions/Policies;
- outbound ports;
- власною persistence semantics;
- module contributions;
- domain capabilities та vocabulary.

Напрямок залежностей:

```text
Kernel         → PHP/core contracts only
Domain         → Kernel + same Domain
Infrastructure → Kernel/Domain contracts
Interfaces     → exposed Application/Kernel services
Bootstrap      → assembles concrete dependencies
```

# Обґрунтування

Ця межа дозволяє:

- додавати нові Domains без модифікації Kernel business logic;
- тестувати runtime mechanisms незалежно від конкретної вертикалі;
- не прив’язувати business core до Phalcon/PDO/provider SDK;
- міняти adapters без переписування Domain rules;
- зберігати один execution model для Web/API/CLI/Telegram/workers;
- будувати Process/Visualization поверх declared contracts замість копіювання бізнес-семантики в Kernel.

# Розглянуті альтернативи

## Товстий shared service layer

Відхилено: shared services неминуче накопичують business vocabulary і створюють приховані cross-domain dependencies.

## Kernel із domain-specific branches

Наприклад `if sales`, `if property`. Відхилено: кожен новий Domain вимагав би змін Kernel.

## Framework modules як бізнес-архітектура

Відхилено: delivery/framework boundary не повинен визначати bounded contexts.

# Наслідки

Позитивні:

- сильні dependency boundaries;
- локальне ownership бізнес-правил;
- replaceable infrastructure;
- стабільний generic runtime;
- можливість машинно перевіряти architecture boundaries.

Вартість:

- більше explicit ports/contracts;
- Bootstrap/composition стає важливішим;
- іноді потрібні окремі Domain adapters замість «одного універсального сервісу».

# Сумісність і міграція

Sales є одним із reference implementations. Property і Diagnostic вже мають власні runtime/module boundaries, а supporting areas дозрівають до installable Domain лише коли мають реальну бізнес-семантику та lifecycle.

Legacy framework/persistence surfaces залишаються compatibility boundaries, а не новою точкою розширення.

# Перевірка

Архітектурні guards мають блокувати:

- Kernel → Domains/Infrastructure/Interfaces/Phalcon/PDO;
- Domain Application/Model → Infrastructure/Interfaces/Phalcon/PDO;
- прямі undeclared cross-domain dependencies;
- повернення business logic у controllers.

Основний executable guard для layer direction: `tests/architecture/layer_dependencies.php`.

# Пов’язані матеріали

- `docs/03-architecture/domain-map.md`
- `docs/03-architecture/cross-domain-contracts.md`
- `docs/09-development/adding-a-domain.md`
