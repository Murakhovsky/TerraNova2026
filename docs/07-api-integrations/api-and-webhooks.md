---
title: API та webhooks
description: Правила HTTP API та webhook-входів від транспортної перевірки до канонічних сценаріїв Domain.
status: active
updated: 2026-09-16
kind: architecture
---

# API та webhooks

API та webhook endpoints є delivery boundary (межею доставки). Вони приймають transport input, але не стають власниками бізнес-стану.

## Канонічний шлях API

```text
HTTP request
   ↓
Authentication / tenant context
   ↓
Capability / permission check
   ↓
Transport validation
   ↓
Application DTO / Command
   ↓
Domain-owned Use Case
   ↓
Result
   ↓
HTTP response
```

Controller не повинен містити SQL, pipeline transitions, provider routing або каталог Domain Policy.

## Шлях webhook

```text
External provider
   ↓
Authenticity / signature validation
   ↓
Normalize external envelope
   ↓
Durable inbox / idempotency boundary
   ↓
Canonical application input
   ↓
Domain Use Case
   ↓
Event / result / audit
```

Повторна доставка webhook є нормальною властивістю зовнішнього світу. Тому stable external identity, payload hash, idempotency semantics і retry-safe processing мають бути явними там, де доставка асинхронна.

## Власність помилок

Розділяйте:

- transport/authentication failure;
- malformed payload;
- provider mapping failure;
- Domain validation/rejection;
- policy/permission deny;
- persistence/concurrency failure;
- external side-effect failure.

HTTP status є представленням причини, а не самою бізнес-семантикою.

## Маршрути

Module-owned API routes реєструються через runtime extension surface. Поточний точний список не дублюється вручну: [Маршрути модулів](../12-reference/module-routes.md) генеруються з актуального checkout.

## Cross-domain правило

API endpoint не є приводом обходити Domain ownership. Якщо один HTTP call оркеструє кілька Domains, кожна мутація все одно проходить через application boundary свого власника.

## Пов’язані сторінки

- [Модель інтеграцій](./integration-model.md)
- [Надійність зовнішніх інтеграцій](./external-reliability.md)
- [Інтерфейсні поверхні](../08-ui/interface-surfaces.md)
