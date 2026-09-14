---
title: COS Mental Model
description: Модель читання COS від бізнес-події до виконаної дії.
status: active
updated: 2026-09-11
kind: concept
---

# COS Mental Model

COS найпростіше розуміти не через класи, а через **ланцюг відповідальності**.

## 1. Business transaction

Спочатку в Domain відбувається реальна бізнес-операція: завершився дзвінок, змінився стан угоди, надійшов webhook, менеджер виконав use case.

Domain змінює власний state і створює business Event.

## 2. Event

Event є фактом, а не командою.

```text
sales.call.completed
```

означає «дзвінок завершився», а не «надішли follow-up».

Business state, Event і Outbox повинні бути зафіксовані узгоджено в одній transaction boundary.

## 3. Durable delivery

Outbox відділяє commit бізнес-операції від наступної автоматизації.

```text
DB commit
   ↓
Outbox
   ↓
Consumer / worker
```

Delivery є at-least-once, тому downstream side effects мають бути idempotent.

## 4. Decision

Подія може пройти через два типи decision logic:

### Rule

Deterministic умова. Однаковий context дає однаковий результат.

### Agent

LLM-based decision. Agent отримує domain-owned context, але повертає **proposal**, а не виконує mutation.

## 5. Action proposal

Рішення матеріалізується як Action / ActionProposal.

```text
sales.send_followup
sales.update_deal
integration.sync_contact
```

Action є контрольованою одиницею mutation.

## 6. Policy

Жодна Action не обходить Policy.

```text
Action
  ↓
Policy
  ├─ AUTO
  ├─ APPROVAL_REQUIRED
  └─ DENIED
```

Default при відсутності явної policy — deny.

## 7. Approval

Якщо Policy вимагає людину, створюється Approval. Agent не може сам собі видати дозвіл, що, як не дивно, корисна властивість і для software, і для людей.

Approval має окремий lifecycle і після рішення повертає Action у контрольований execution path.

## 8. Queue

LLM work, mutation та зовнішні інтеграції виконуються через durable jobs там, де потрібна асинхронність, retry, lease або dead letter.

Queue — частина reliability model, не «фонова оптимізація».

## 9. Execution

Action executor знаходить handler через module registry / routing і викликає domain-owned implementation.

Handler працює через outbound ports. Він не повинен знати конкретний CRM provider, SQL connection або HTTP client.

## 10. Result

Kernel завершує lifecycle generic events:

```text
cos.action.completed
cos.action.failed
```

Domain, якщо змінив власний бізнес-state, створює власний business Event.

Kernel не вигадує бізнес-події за Domain.

## 11. Audit + Observability

На виході ми повинні мати відповідь:

- що сталося;
- який context був використаний;
- яке правило або Agent прийняли рішення;
- яку Action запропоновано;
- яка Policy спрацювала;
- чи було Approval;
- хто його прийняв;
- який handler виконав дію;
- що повернула зовнішня система;
- скільки це тривало;
- де сталася помилка.

## Ментальна формула

```text
FACT
 → DECISION
 → PROPOSAL
 → PERMISSION
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
Use Case / Event
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

## Сім питань для дебагу будь-якого процесу

1. Який бізнес-факт стався?
2. Який Domain ним володіє?
3. Який Event був створений?
4. Що прийняло рішення: Rule чи Agent?
5. Яка Action була запропонована?
6. Яка Policy дозволила або заблокувала її?
7. Де записані Result та Audit?

Якщо відповідей немає, проблема майже напевно не в тому, що «AI щось не зрозумів». Система просто ще не має чіткої причинно-наслідкової траси.
