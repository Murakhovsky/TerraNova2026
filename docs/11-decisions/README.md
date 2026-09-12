---
title: Architecture Decisions
description: ADR для рішень, що визначають довгострокову архітектуру COS.
status: active
updated: 2026-09-12
kind: index
---

# Architecture Decisions

Цей розділ містить **ADR (Architecture Decision Records)**: короткі документи, які пояснюють не лише *що* реалізовано, а *чому саме так*.

Код показує executable reality. Current architecture docs пояснюють поточну систему. ADR зберігає rationale, alternatives і consequences конкретного довгоживучого рішення.

## Accepted ADRs

| ADR | Decision |
| --- | --- |
| [ADR-0001](ADR-0001-kernel-domain-ownership.md) | Kernel owns mechanisms; Domains own business semantics |
| [ADR-0002](ADR-0002-state-events-outbox.md) | MySQL state + Event + Outbox; COS is not Event Sourcing |
| [ADR-0003](ADR-0003-agents-propose-actions.md) | Agents propose Actions; mutation проходить Policy runtime |
| [ADR-0004](ADR-0004-module-owned-extension-contributions.md) | Modules own extension contributions; shared layers не hardcode-ять Domains |
| [ADR-0005](ADR-0005-governed-structured-llm-runtime.md) | Structured LLM access проходить centralized governance runtime |
| [ADR-0006](ADR-0006-deployed-modules-vs-tenant-activation.md) | Deployed module discovery відокремлена від tenant activation/lifecycle |

Ці ADR формалізують уже реалізовану AS-IS архітектуру. Вони не додають нової поведінки самі по собі.

## Коли потрібен ADR

ADR створюється, коли рішення:

- змінює dependency direction;
- вводить або прибирає Kernel mechanism;
- змінює module contract;
- визначає persistence model;
- змінює tenant isolation/lifecycle model;
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
title: ADR-0007 — Назва рішення
status: proposed
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

- `proposed` — рішення ще обговорюється;
- `accepted` — чинне architectural rule;
- `superseded` — замінене новим ADR;
- `deprecated` — більше не рекомендоване, але історично важливе;
- `rejected` — розглянуте і свідомо відхилене.

Accepted ADR не переписується так, ніби попереднього рішення не існувало. Якщо архітектура змінилась, створюється новий ADR, а попередній отримує `superseded` із посиланням на replacement.

## Truth hierarchy

```text
Executable code + tests
    ↓
Current architecture/product docs
    ↓
Accepted ADR rationale/history
    ↓
Legacy migration notes
```

Якщо ADR і code розходяться, треба або завершити implementation, або supersede рішення. Дві несумісні «правди» не є гнучкою архітектурою, це просто борг у гарному костюмі.

## Existing decision-like documents

У `docs/architecture/` зберігаються детальні технічні документи та migration plans, частина яких історично виконувала роль ADR.

Надалі:

- `03-architecture/` та `architecture/` описують current structure/constraints;
- `11-decisions/` зберігає rationale довгоживучих рішень;
- code/tests залишаються executable source of truth.
