---
title: Надійність зовнішніх інтеграцій
description: Ідемпотентність, retries, timeouts, dead-letter і спостережуваність для зовнішніх інтеграцій COS.
status: active
updated: 2026-09-16
kind: architecture
---

# Надійність зовнішніх інтеграцій

Зовнішня система може відповісти двічі, через хвилину, не відповісти взагалі або повернути `200`, після якого фактична операція впаде десь усередині. Інтернет, як завжди, демонструє характер.

## Контракт надійності

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

## Ідемпотентність

Idempotency key має бути scoped так, щоб повторна доставка тієї самої бізнес-операції не створювала другий side effect, але нова легітимна операція не блокувалась старим ключем.

Типовий scope:

```text
organization + integration + operation type + stable operation id
```

## Класифікація retry

Не кожна помилка є retryable.

Retry зазвичай доречний для timeout, transient transport failure або provider 5xx/rate-limit semantics. Domain rejection, invalid payload, denied permission або broken mapping не повинні нескінченно крутитися в Queue.

## Надійна обробка

Inbound events, втрата яких має бізнес-наслідки, повинні проходити через durable inbox/queue boundary. Outbound work з retries має мати lease/attempt/result semantics і dead-letter або еквівалентний terminal state.

## Correlation і audit

Для зовнішньої операції має бути можливо пов’язати:

- originating user/event/action;
- Domain operation;
- integration/provider;
- request attempts;
- external identifier;
- final result/error;
- retry/dead-letter history.

## Ізоляція помилок

Provider outage не повинен зупиняти unrelated Domains або весь Kernel. Adapter/runtime boundary має локалізувати failure і дозволити контрольовану degradation.

## Примітка про LLM

LLM routing/fallback має власні керовані abstractions. Інші integrations не повинні копіювати LLM-specific contracts, але використовують той самий принцип: transport failure не є business decision.

## Пов’язані сторінки

- [Події та Outbox](../05-runtime/events-and-outbox.md)
- [Аудит і діагностика виконання](../05-runtime/audit-and-diagnostics.md)
- [API та webhooks](./api-and-webhooks.md)
