---
title: Що таке COS
description: Продуктова й архітектурна роль Company Operating System.
status: active
updated: 2026-09-11
kind: concept
---

# Що таке COS

**COS (Company Operating System)** — це платформа виконання бізнес-процесів, де люди, правила, AI-агенти, автоматизації та зовнішні системи працюють через один контрольований runtime.

COS не є просто CRM, набором AI-чатів або бібліотекою інтеграцій. Його задача — перетворювати бізнес-події на контрольовані рішення та дії.

```text
Something happened
    ↓
COS understands context
    ↓
Rule or Agent proposes what to do
    ↓
Policy decides whether it may be done
    ↓
Human approves when required
    ↓
COS executes through a controlled adapter
    ↓
Result is recorded and can trigger the next process
```

## Що Kernel дає кожному Domain

Kernel надає універсальні механізми:

- Event та durable Outbox;
- deterministic Rules;
- Agent runtime;
- Action lifecycle;
- Policy gate;
- Human Approval;
- durable Queue;
- Audit trail;
- tenant isolation;
- Configuration;
- Operations та Observability;
- Module lifecycle.

## Що належить Domain

Domain володіє бізнес-мовою:

- сутностями та інваріантами;
- use cases;
- бізнес-подіями;
- rule context;
- agent context;
- action types;
- action handlers;
- domain policies;
- outbound ports.

Наприклад, Sales знає, що таке угода, дзвінок, follow-up та pipeline. Kernel знає лише, як надійно провести event → decision → action → policy → execution.

## Головна межа

```text
Kernel = HOW
Domain = WHAT + WHY
Infrastructure = WITH WHAT
Interface = WHO / FROM WHERE
Bootstrap = HOW EVERYTHING IS ASSEMBLED
```

Ця межа дозволяє підключати Finance, HR, Inventory чи інший Domain без перетворення Kernel на колекцію `if ($domain === 'sales')`.

## Agentic AI у COS

AI не отримує необмежений доступ до системи.

У поточній моделі Agent:

1. отримує redacted context;
2. викликає LLM через контракт;
3. повертає structured decision;
4. пропонує одну або кілька Actions;
5. не виконує mutation самостійно.

Після цього звичайний Kernel runtime застосовує Policy, Approval, Queue та Action executor.

Це ключова відмінність між COS і «LLM з доступом до бази».

## Навіщо модульність

Domain повинен мати можливість бути виявленим, встановленим, активованим, деактивованим та оновленим як модуль.

Для цього Kernel уже містить module manifest, discovery, catalog, lifecycle manager, active resolver, capability registry та version constraints.

Отже модульність у COS — не організація папок. Це runtime contract.

## Поточний reference implementation

Sales є першим повноцінним business Domain і reference implementation для наступних Domains.

Його задача не в тому, щоб зробити Kernel sales-specific. Навпаки: Sales має довести, що generic Kernel може виконувати реальний бізнес-процес без знання його внутрішньої семантики.

## Найкоротше визначення

> COS — це контрольований runtime компанії, який перетворює бізнес-події на дозволені, пояснювані та відтворювані дії людей, software та AI-агентів.
