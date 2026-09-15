---
title: API & Webhooks
description: HTTP API and webhook delivery rules from transport validation to canonical Domain use cases.
status: active
updated: 2026-09-15
kind: architecture
---

# API & Webhooks

API та webhook endpoints є delivery boundary. Вони приймають transport input, але не стають власниками business state.

## Canonical API path

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

Controller не повинен містити SQL, pipeline transitions, provider routing або Domain policy catalog.

## Webhook path

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

Webhook redelivery вважається нормальною властивістю зовнішнього світу, а не диверсією провайдера. Тому stable external identity, payload hash/idempotency semantics і retry-safe processing повинні бути explicit там, де delivery asynchronous.

## Error ownership

Розділяйте:

- transport/authentication failure;
- malformed payload;
- provider mapping failure;
- Domain validation/rejection;
- policy/permission deny;
- persistence/concurrency failure;
- external side-effect failure.

HTTP status/response є presentation цієї причини, а не самою business semantics.

## Routes

Module-owned API routes реєструються через runtime extension surface. Exact current routes не дублюються тут: [Module Routes](../12-reference/module-routes.md) генерується з current checkout.

## Cross-domain rule

API endpoint не є приводом обходити Domain ownership. Якщо один HTTP call оркеструє кілька Domains, кожна mutation все одно проходить через owner-specific application boundary.

## Related

- [Integration Model](./integration-model.md)
- [External Reliability](./external-reliability.md)
- [Interface Surfaces](../08-ui/interface-surfaces.md)
