---
title: Спостережуваність та інциденти
description: Logs, metrics, traces, queue signals і модель triage для операцій COS.
status: active
updated: 2026-09-16
kind: operations
---

# Спостережуваність та інциденти

Спостережуваність COS має дозволяти перейти від «щось не працює» до конкретної причинно-наслідкової траси.

## Кореляційний ланцюжок

```text
request / event / job
→ correlation id
→ tenant
→ Domain operation
→ policy / agent decision
→ persistence / external calls
→ result / error
```

`correlation id` має переживати queue/event/external hops, де це можливо.

## Logs

Structured log для consequential operation повинен, де доречно, містити:

- timestamp/severity;
- correlation/request/job id;
- organization/tenant reference;
- Domain/use case/action;
- outcome/error class;
- provider/integration reference без secret leakage.

Лог не повинен вимагати від оператора ворожіння по трьох unrelated stack traces, щоб зрозуміти одну бізнесову операцію.

## Metrics

Корисні категорії:

- request/use-case latency та error rate;
- event/outbox backlog;
- queue depth, attempts, dead-letter count;
- worker heartbeat/readiness;
- external provider latency/failure;
- Agent/LLM latency, usage/cost, schema failures;
- approval backlog;
- Domain-specific operational KPIs.

## Tracing

Critical cross-boundary flows мають бути reconstructable навіть якщо повний distributed tracing не використовується.

Для важливої операції має бути можливо відновити:

```text
хто / що ініціював
→ який Domain прийняв запит
→ яке рішення Policy/Agent було прийнято
→ які записи та події створено
→ чи потрапила робота в queue
→ який external effect виконано
→ який фінальний result/error
```

## Triage інциденту

Порядок діагностики:

1. визначити user-visible symptom і tenant scope;
2. знайти correlation/request/job;
3. визначити Domain owner/use case;
4. перевірити permission/policy/approval result;
5. перевірити DB transaction/Event/Outbox;
6. перевірити queue/worker;
7. перевірити зовнішній provider;
8. визначити blast radius;
9. зафіксувати root cause, recovery action і follow-up control.

## Якість alert-ів

Alert має означати actionable condition.

Якщо система надсилає 400 повідомлень про кожен retry, люди швидко винаходять найнадійніший monitoring tool: mute.

Тому alerting має відрізняти transient retry від terminal failure, локальну помилку одного tenant від системної деградації та warning від реального incident condition.

## Пов’язані сторінки

- [Audit & Diagnostics](../05-runtime/audit-and-diagnostics.md)
- [Надійність зовнішніх інтеграцій](../07-api-integrations/external-reliability.md)
- [Розгортання та перевірка стану](./deployment-and-health.md)
