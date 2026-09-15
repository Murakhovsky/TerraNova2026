---
title: External Reliability
description: Idempotency, retries, timeouts, dead-letter behavior and observability for external COS integrations.
status: active
updated: 2026-09-15
kind: architecture
---

# External Reliability

Зовнішня система може відповісти двічі, через хвилину, не відповісти взагалі або повернути `200`, після якого фактична операція провалиться десь усередині. Інтернет, як завжди, демонструє характер.

## Reliability contract

Для consequential external side effect визначте:

```text
Stable operation identity
→ timeout
→ error classification
→ retry policy
→ idempotency semantics
→ final result / dead-letter
→ audit + correlation
```

## Idempotency

Idempotency key має бути scoped так, щоб повторна доставка тієї самої business operation не створювала другий side effect, але нова легітимна операція не блокувалась старим ключем.

Типовий scope:

```text
organization + integration + operation type + stable operation id
```

## Retry classification

Не кожна помилка retryable.

Retry зазвичай доречний для timeout, transient transport failure або provider 5xx/rate-limit semantics. Domain rejection, invalid payload, denied permission або broken mapping не повинні нескінченно крутитися в queue.

## Durable processing

Inbound events, для яких втрата має business consequence, повинні проходити через durable inbox/queue boundary. Outbound work з retries повинно мати lease/attempt/result semantics і dead-letter або equivalent terminal state.

## Correlation and audit

Для зовнішньої операції має бути можливо пов'язати:

- originating user/event/action;
- Domain operation;
- integration/provider;
- request attempt(s);
- external identifier;
- final result/error;
- retry/dead-letter history.

## Circuit/failure isolation

Provider outage не повинен зупиняти unrelated Domains або весь Kernel. Adapter/runtime boundary має локалізувати failure і дозволити контрольоване degradation.

## LLM note

LLM routing/fallback має власні governed abstractions. Інші integrations не повинні копіювати LLM-specific contracts, але використовують той самий принцип: transport failure не є business decision.

## Related

- [Events & Outbox](../05-runtime/events-and-outbox.md)
- [Audit & Diagnostics](../05-runtime/audit-and-diagnostics.md)
- [API & Webhooks](./api-and-webhooks.md)
