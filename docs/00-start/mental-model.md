---
title: COS Mental Model
description: Модель читання COS від бізнес-наміру до контрольованого виконання і результату.
status: active
updated: 2026-09-14
kind: concept
---

# COS Mental Model

COS найпростіше розуміти не через класи, а через **ланцюг відповідальності**.

## Два основні execution paths

У системі є два природні шляхи.

### Direct use case

```text
User / Interface
    ↓
Use Case / Command
    ↓
Domain validation
    ↓
State change
    ↓
Event + Result
```

### Event-driven automation

```text
Business Event
    ↓
Rule / Agent
    ↓
Action proposal
    ↓
Policy
    ↓
Approval / Auto / Deny
    ↓
Execution
    ↓
Result Event + Audit
```

Не кожна операція потребує Agent, Queue чи Approval. COS не повинен перетворювати простий update на релігійний ритуал із сімнадцяти мікросервісів.

## 1. Intent / business operation

На початку є конкретна бізнес-мета: створити lead, змінити stage, почати diagnostic session, прийняти property submission або виконати іншу domain operation.

Interface лише передає намір у application boundary.

## 2. Use Case / Command

Application layer оркеструє meaningful operation.

```text
Input
→ validation/context
→ domain/application logic
→ persistence/ports
→ result
```

Command є запитом щось зробити. Event є фактом, що щось уже сталося. Плутати їх зручно лише до першої серйозної автоматизації.

## 3. Domain ownership

Domain визначає:

- vocabulary;
- invariants;
- state transitions;
- business events;
- contracts;
- authority над своїми даними.

Kernel не вирішує, чи Lead qualified. Web controller не вирішує, чи diagnostic methodology валідна. Infrastructure не вирішує, що означає property moderation.

## 4. Event

Event описує факт.

```text
sales.call.completed
```

означає «дзвінок завершився», а не «надішли follow-up».

Business state + Event + Outbox повинні мати узгоджену transaction boundary там, де Event запускає подальший processing.

## 5. Durable delivery

```text
DB commit
   ↓
Outbox
   ↓
Consumer / worker
```

Delivery може бути at-least-once, тому side effects мають бути idempotent.

## 6. Decision

Після Event рішення може приймати:

### Rule

Deterministic logic.

### Agent

LLM-assisted decision logic на domain-owned context.

Agent повертає proposal. Він не отримує право мутувати систему лише тому, що вміє писати переконливі JSON-и.

## 7. Action proposal

Рішення матеріалізується як контрольована Action / ActionProposal.

```text
sales.send_followup
sales.update_deal
integration.sync_contact
```

## 8. Policy

Action проходить authority check:

```text
Action
  ↓
Policy
  ├─ AUTO
  ├─ APPROVAL_REQUIRED
  └─ DENIED
```

Default-deny лишається безпечнішим baseline для mutation authority.

## 9. Approval

Якщо потрібна людина, створюється окремий Approval lifecycle.

Agent не затверджує власне рішення. Люди іноді теж не повинні, але для них ми поки не написали Kernel.

## 10. Queue / Execution

Durable Queue використовується там, де потрібні asynchronous execution, retries, leases або dead-letter semantics.

Executor знаходить domain-owned handler через runtime/module registry та викликає outbound ports/adapters.

## 11. Result

Kernel може завершувати generic execution lifecycle events, а Domain створює власні business events, коли змінено business state.

Kernel не вигадує domain semantics за Domain.

## 12. Audit + Observability

На виході система повинна дозволяти відповісти:

- що сталося;
- який Domain володів операцією;
- який context використано;
- яке правило/Agent прийняли рішення;
- яку Action запропоновано;
- яка Policy спрацювала;
- чи був Approval;
- який handler виконав mutation;
- який external call відбувся;
- який Result отримано;
- де сталася помилка.

## Ментальна формула

```text
INTENT
 → DOMAIN OPERATION
 → FACT
 → DECISION
 → PROPOSAL
 → AUTHORITY
 → EXECUTION
 → RESULT
 → EXPLANATION
```

## Рівні системи

```text
Business
  ↓
Workflow
  ↓
Domain
  ↓
Use Case / Command / Event
  ↓
Kernel Runtime
  ↓
Policy / Approval / Queue
  ↓
Domain Port
  ↓
Infrastructure Adapter
  ↓
External System / Database
```

## Branch mental model

Для самої документації є ще один важливий поділ:

```text
COS  = executable truth
main = human-readable knowledge + WEB
```

Тому AS-IS твердження мають підтверджуватися `COS` code/tests або generated reference, синхронізованим із `COS`.

## Сім питань для дебагу

1. Який бізнес-наміp або факт ми обробляємо?
2. Який Domain ним володіє?
3. Який Use Case/Command/Event представляє операцію?
4. Де приймається рішення: domain logic, Rule чи Agent?
5. Яка Action/transition виконується?
6. Яка authority/policy дозволила її?
7. Де записані Result, Event та Audit?

Якщо немає чітких відповідей, проблема зазвичай не в AI. Причинно-наслідкова траса просто ще не сформована.
