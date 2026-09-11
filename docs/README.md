---
title: COS Documentation
description: Канонічна документація Company Operating System у гілці COS.
status: active
updated: 2026-09-11
kind: index
---

# COS Documentation

Ця директорія є канонічним входом у документацію **Company Operating System**.

Документація описує актуальну архітектуру гілки `COS`: Kernel, Domains, runtime виконання, agentic loop, policies, approvals, queue, audit, observability та модульний контракт.

> Важливо: старі Terra Nova документи в корені `docs/` описують попередню прикладну платформу. Вони корисні як історія та integration reference, але не визначають архітектуру COS Kernel.

## Як читати COS

Рекомендований порядок:

1. [Що таке COS](00-start/what-is-cos.md)
2. [Mental Model](00-start/mental-model.md)
3. [Карта репозиторію](00-start/repository-map.md)
4. [Kernel Overview](03-architecture/kernel-overview.md)
5. [Canonical Kernel Architecture](architecture/cos-kernel.md)
6. [Execution Lifecycle](05-runtime/execution-lifecycle.md)
7. [Events & Outbox](05-runtime/events-and-outbox.md)
8. [Policies & Approvals](05-runtime/policies-and-approvals.md)
9. [Module Lifecycle](05-runtime/module-lifecycle.md)
10. [Agent Runtime](06-ai-agents/agent-runtime.md)
11. [Context & Tools](06-ai-agents/context-and-tools.md)
12. [Audit & Diagnostics](05-runtime/audit-and-diagnostics.md)
13. [Kernel Components Reference](12-reference/kernel-components.md)

## Головна модель

```text
Business transaction
    ↓
Domain state change
    ↓
Event + Outbox
    ↓
Durable consumer
    ↓
Rule / Agent
    ↓
Action proposal
    ↓
Policy
    ├─ DENIED
    ├─ APPROVAL_REQUIRED → Human Approval
    └─ AUTO
         ↓
Queue / Execution
         ↓
Domain port / Infrastructure adapter
         ↓
Result Event
         ↓
Audit + Metrics + next automation
```

## Архітектурні рівні

```text
Interfaces
    ↓
Application / Kernel services
    ↓
Domains
    ↓
Kernel contracts

Infrastructure implements ports
Bootstrap assembles concrete dependencies
```

Kernel володіє **механізмами**, Domain володіє **бізнес-смислом**.

Kernel знає, як виконати Action, застосувати Policy, створити Approval, поставити Job у Queue та записати Audit. Kernel не повинен знати, що означає `sales.send_followup`, яка стадія угоди є доброю або кому саме треба телефонувати.

## AS-IS та TARGET

У цій документації:

- `AS-IS` означає поведінку, підтверджену кодом гілки `COS`;
- `TARGET` означає принцип або наступний архітектурний крок, який ще не повністю реалізований.

Не змішуємо ці дві речі. Архітектурна документація, яка описує бажане як готове, корисна приблизно як карта метро з вигаданими станціями.

## Існуючі детальні документи

Зберігаються і залишаються джерелом деталей:

- `docs/architecture/cos-kernel.md` — canonical architecture;
- `docs/architecture/domain-boundaries.md` — bounded contexts;
- `docs/architecture/persistence.md` — persistence rules;
- `docs/architecture/diagnostic-domain-model.md` — diagnostic domain;
- `docs/architecture/frontend-interface.md` — interface architecture;
- `docs/architecture/legacy-modules-integration-plan.md` — migration from legacy application;
- `docs/diagnostic/` — methodology and diagnostic assets.

## Source of truth

Код визначає виконувану реальність. Документація пояснює її смисл, межі та правила.

Коли вони розходяться, це defect документації або defect архітектури, а не привід удавати, що конфлікту немає.
