---
title: Платформа асинхронних операцій Web
description: Канонічна projection-модель Queue jobs, Activity Center, notifications, retry та realtime lifecycle для довгих операцій COS.
status: active
updated: 2026-09-21
kind: architecture
---

# Платформа асинхронних операцій Web

Wave 12.13 робить довгі та фонові операції видимими у Web, не створюючи другого queue runtime.

Авторитетний execution path лишається:

```text
Application Command / Kernel Action / Agent / Integration
        ↓
Kernel Queue
        ↓
cos_jobs
        ↓
QueueWorker
        ↓
handler
        ↓
COMPLETED / FAILED / DEAD
```

Web читає цей lifecycle через presentation-neutral read contract і показує його як `AsyncOperation`.

## Модель асинхронної операції

`AsyncOperationProjection` містить:

- id;
- organization;
- type;
- status;
- attempts / max attempts;
- correlation id;
- created / updated / available / started / finished timestamps;
- nullable progress;
- nullable actor;
- nullable entity;
- nullable worker;
- nullable result;
- nullable error;
- retry scheduled;
- manual retry capability.

Progress є nullable навмисно. Якщо конкретний job не зберігає достовірний прогрес, UI показує indeterminate state, а не вигаданий відсоток.

## Відображення станів Queue

Поточна Queue schema має `PENDING`, `RUNNING`, `COMPLETED`, `FAILED`, `DEAD`.

Web projection нормалізує їх:

| Queue | AsyncOperation |
| --- | --- |
| `PENDING` | `queued` |
| `RUNNING` | `running` |
| `COMPLETED` | `completed` |
| `FAILED` | `failed` + automatic retry scheduled |
| `DEAD` | `failed` + manual retry available |

`cancelled` є частиною canonical Web status vocabulary, але поточний Kernel Queue ще не має cancel lifecycle. UI не симулює cancellation.

## Модель читання

Kernel визначає `AsyncOperationReadModelInterface`.

MySQL adapter читає лише tenant-scoped jobs:

```text
organization_id + operation id
organization_id + recent limit
```

Для `ACTION_EXECUTION` projection може приєднати target entity з `cos_actions`.

Для Agent jobs entity може бути отримана з `subject_type / subject_id`.

Це read-only enrichment. Web не читає raw queue rows напряму.

## Центр активності

Shell має одну canonical Activity Center panel.

Topbar actions:

- `AC` відкриває activity feed;
- `NT` відкриває notifications.

Обидві поверхні використовують той самий Turbo Frame.

`WebExtensionContextCatalog` агрегує:

```text
platform async operations
+
module web.activity contributions
+
module web.notifications contributions
```

Новий registry не створюється.

## Сповіщення

Queue failure projection:

- retryable `FAILED` → warning;
- terminal `DEAD` → danger.

Notification не є audit record і не є джерелом business truth.

Вона лише посилається на canonical operation resource в Activity Center.

## Повтор операції

Manual retry дозволений лише для `DEAD` job.

Потік:

```text
POST + CSRF
    ↓
Tenant permission: cos.tenant.manage
    ↓
RetryAsyncOperationCommand
    ↓
AsyncOperationReadModel tenant check
    ↓
JobQueueInterface::replayDead()
```

Controller не викликає Queue напряму.

Command handler повторно перевіряє operation state. Browser-supplied id не є authorization proof.

## Життєвий цикл realtime

Queue lifecycle використовує вже наявний durable EventBus/outbox path.

`MysqlJobQueue` на переходах enqueue, claim, complete, fail/dead, timeout recovery та replay записує `kernel.queue.operation.changed` у тій самій транзакції, що й зміна `cos_jobs`.

Потік:

```text
Queue state transition
      ↓ same DB transaction
DomainEvent + cos_event_outbox
      ↓ after commit
OutboxPublisher
      ↓ durable consumer checkpoint
AsyncOperationRealtimeEventConsumer
      ↓
private Mercure organization topic
```

Consumer читає свіжу `AsyncOperationProjection` і публікує lightweight signal.

Signal містить лише operation identity, status і timestamp. Повний job payload через realtime не передається.

Activity Center після signal перечитує authoritative server projection через Turbo Frame.

## Відмова Mercure

Mercure не є частиною Queue transaction.

Якщо Hub недоступний:

- Queue transition уже committed;
- outbox delivery позначається failed;
- діють existing outbox retry/dead-letter semantics;
- job state не відкочується;
- після reload Activity Center все одно читає authoritative projection.

Таким чином realtime доставка durable, але не стає джерелом business truth.

## Кореляція та спостережуваність

Activity Center показує `correlationId`.

Це дозволяє пов'язати operation із:

- Action;
- Agent run;
- worker logs;
- audit trail;
- external integration;
- incident triage.

Correlation id не замінює audit record.

## Мобільний режим

На вузькому viewport Activity Center стає bottom sheet.

Зберігаються:

- safe area;
- scrollable operation list;
- detail state;
- retry action;
- notifications tab.

Окремої mobile business logic не існує.

## Межі

Заборонено:

- читати `cos_jobs` з Twig або Stimulus;
- створювати окрему browser queue;
- polling через custom `fetch()`;
- localStorage як operation state;
- дозволяти realtime failure ламати Queue;
- робити retry напряму з controller у persistence;
- вигадувати progress, якого немає в authoritative source;
- використовувати UI status як authorization proof.

## Перевірка

`cos:web:async-operations:smoke`:

1. створює synthetic terminal job;
2. читає його через `AsyncOperationReadModelInterface`;
3. перевіряє Activity projection;
4. перевіряє Notification projection;
5. рендерить Activity Center;
6. запускає retry через `CommandBus`;
7. перевіряє повернення operation у `queued`;
8. видаляє synthetic record.

Architecture gate окремо перевіряє dependency direction, CSRF/CommandBus boundary, Shell integration та відсутність custom browser transport/state.
