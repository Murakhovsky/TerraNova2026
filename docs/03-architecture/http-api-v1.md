---
title: HTTP API v1
description: Канонічна Symfony HTTP/API межа COS з тонкими controllers та Application buses.
status: active
updated: 2026-09-18
kind: architecture
contract: architecture-v1
---

# HTTP API v1

Канонічний versioned HTTP surface COS починається з `/api/v1/`. Symfony відповідає за routing, authentication/security і transport mapping; бізнес-логіка залишається в Application/Domain.

## Потік запиту

```text
HTTP request
   ↓
Request DTO / transport mapping
   ↓
Command або Query
   ↓
CommandBus / QueryBus
   ↓
Application handler
   ↓
Domain / ports
   ↓
response DTO / JSON
```

Перший migration-proof endpoint — `GET /api/v1/status`. Controller створює тільки `GetApiStatusQuery`, передає його у `QueryBusInterface` та формує HTTP response з результату handler.

## Інваріанти

Controller не виконує SQL, не викликає GPT/Telegram/external SDK і не містить domain decisions. Він також не приймає tenant identity з query/body: identity та tenant context формуються Security layer.

Для payload-bearing endpoints стандартом є Serializer + Validator перед створенням Command/Query. На поточному status slice payload відсутній, тому ці компоненти не викликаються штучно; їх підключення до runtime package set виконується разом із першим write/read DTO, що реально потребує deserialization та validation.

Legacy migration routes `/migration/api/cos/*` залишаються паралельно, доки відповідні vertical slices не переключені на `/api/v1/*`.
