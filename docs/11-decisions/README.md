---
title: Architecture Decisions
description: Правила ведення ADR для рішень, що змінюють довгострокову архітектуру COS.
status: active
updated: 2026-09-12
kind: index
---

# Architecture Decisions

Цей розділ призначений для **ADR (Architecture Decision Records)** — коротких документів, які пояснюють не лише *що* зроблено, а *чому саме так*.

Код показує поточну реалізацію. ADR пояснює рішення, альтернативи та наслідки. Це різні задачі, і змішувати їх в один README приблизно так само корисно, як вести бухгалтерію в коментарях до PHP.

## Коли потрібен ADR

ADR створюється, коли рішення:

- змінює dependency direction;
- вводить або прибирає Kernel mechanism;
- змінює module contract;
- визначає persistence model;
- змінює tenant isolation model;
- вводить новий extension point із довгим lifecycle;
- визначає LLM/Agent governance boundary;
- змінює delivery/event semantics;
- створює compatibility constraint між великими частинами COS;
- дорого або ризиковано відкотити.

Не потрібен окремий ADR для перейменування класу, косметичного refactor або очевидної локальної реалізації.

## Формат файлу

```text
ADR-0001-short-title.md
ADR-0002-another-decision.md
```

Рекомендований шаблон:

```markdown
---
title: ADR-0001 — Назва рішення
status: accepted
updated: YYYY-MM-DD
kind: decision
---

# Context
Яку проблему вирішуємо і які constraints існують.

# Decision
Що саме вирішили.

# Rationale
Чому цей варіант обраний.

# Alternatives considered
Які реальні альтернативи відхилили і чому.

# Consequences
Позитивні, негативні та operational наслідки.

# Compatibility / Migration
Що потрібно змінити в існуючому коді або даних.

# Verification
Які tests/guards підтверджують рішення.

# Related
Код, docs, commits, інші ADR.
```

## Status values

Використовуємо:

- `proposed` — рішення ще обговорюється;
- `accepted` — чинне architectural rule;
- `superseded` — замінене новим ADR;
- `deprecated` — більше не рекомендоване, але історично важливе;
- `rejected` — розглянуте і свідомо відхилене.

Не переписуємо старий accepted ADR так, ніби минулого рішення не існувало. Якщо архітектура змінилась, створюємо новий ADR і позначаємо попередній `superseded`.

## AS-IS vs decision history

ADR не є автоматично описом поточного code state.

Поточна truth hierarchy:

```text
Executable code + tests
    ↓
Current architecture/product docs
    ↓
Accepted ADR rationale/history
    ↓
Legacy migration notes
```

Якщо ADR і code розходяться, треба або завершити implementation, або supersede/update рішення. Не треба робити вигляд, що обидві реальності однаково правильні.

## Вже існуючі decision-like documents

У `docs/architecture/` уже є технічні документи й migration plans, які частково виконують роль історичних ADR.

Нові довгострокові рішення варто фіксувати тут окремими ADR, а детальні поточні architecture docs залишати в `03-architecture/` та `architecture/`.

## Перші рішення, які варто формалізувати

Із поточної архітектури природними кандидатами є:

1. Kernel owns mechanisms, Domains own business semantics.
2. MySQL state + Event + Outbox замість Event Sourcing.
3. Agents are proposal-only; mutation проходить Action/Policy runtime.
4. Modules declare extension services; shared layers не hardcode-ять Domains.
5. Structured LLM access проходить centralized governance runtime.
6. Tenant activation відокремлена від deployed module discovery.

Ці правила вже підтримуються кодом, але окремі ADR зроблять причини рішень явними для наступних змін.
