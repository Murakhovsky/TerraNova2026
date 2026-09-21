---
title: Платформа realtime Web
description: Канонічна приватна доставка server-rendered Turbo Streams через Mercure для tenant-scoped Workspace, entity та user projections.
status: active
updated: 2026-09-21
kind: architecture
---

# Платформа realtime Web

Wave 12.12 додає realtime як transport layer Experience Platform.

Realtime **не є другим сховищем стану** і не створює новий шлях виконання бізнес-операцій.

Авторитетний потік лишається:

```text
Command / Action
      ↓
Application + Domain / Kernel
      ↓
commit authoritative state
      ↓
read projection
      ↓
Turbo Stream
      ↓
Mercure
      ↓
subscribed Workspace
```

## Ідентичність topic

`RealtimeTopicFactory` створює tenant-scoped IRI.

Підтримуються чотири базові scope:

- organization;
- workspace;
- entity;
- user.

Приклад:

```text
https://realtime.cos.internal/organizations/default/workspaces/sales.deal
```

IRI є identity для transport і authorization. Він не зобов'язаний бути Web route.

## Приватні підписки

COS публікує realtime updates як private Mercure updates.

Subscriber отримує JWT cookie лише для topic selector, який server render додає через `turbo_stream_listen()`.

Web browser використовує `withCredentials`.

Global anonymous subscription не є канонічним режимом COS.

## Публікація

`RealtimeStreamPublisher`:

1. приймає typed `RealtimeTopic`;
2. дозволяє лише templates із `experience/realtime/streams/`;
3. рендерить Twig;
4. перевіряє наявність `<turbo-stream>`;
5. публікує private `Mercure Update`.

Publisher не приймає Domain Entity і не виконує mutation.

## Підписка через Turbo

`CosRealtimeSubscription` використовує Symfony UX Turbo 2.x transport:

```text
turbo_stream_listen(topic, default, subscribe + withCredentials)
```

Mercure Stimulus transport увімкнений централізовано через `controllers.json`.

Після окремого майбутнього переходу COS на Symfony UX 3.1+ transport можна змінити без зміни topic contract або publisher.

## Стан з'єднання

`realtime_connection_controller.js` керує лише presentation state:

- `live`;
- `reconnecting`;
- `offline`.

Controller слухає browser online/offline та факт отримання Turbo Stream.

Він не створює власний `EventSource`, не парсить business payload і не робить fetch.

## Інфраструктура

Canonical Docker stack містить один Mercure Hub.

```text
Browser
  ↓ same-origin /.well-known/mercure
Nginx
  ↓
Mercure Hub

Symfony PHP
  ↓ internal http://mercure/.well-known/mercure
Mercure Hub
```

Nginx вимикає buffering для SSE.

Hub має окремий readiness healthcheck і persistent data/config volumes.

## Надійність

Mercure відповідає за transport reconnect та replay semantics.

Business state не відновлюється з Mercure history.

Після reload або reconnect клієнт завжди може отримати authoritative server-rendered projection.

Realtime update має бути ідемпотентним на presentation layer: повторний `update` або `replace` не повинен змінювати business truth.

## Безпека

Realtime topic завжди включає organization id.

Private subscriber token видається server-side.

Заборонено:

- anonymous production subscriptions;
- передавати tenant id із browser як authority;
- публікувати business mutation через Mercure;
- виконувати SQL із realtime publisher;
- створювати WebSocket runtime паралельно Mercure;
- зберігати authoritative state у JavaScript;
- дублювати Kernel EventBus у Web.

## Еталонна поверхня

`/dev/realtime` є manager-only reference.

Дві відкриті вкладки підписуються на той самий private tenant/workspace topic. POST із CSRF публікує Turbo Stream, який оновлює іншу вкладку без polling.

## Перевірка

CI перевіряє:

- Mercure dependency і bundle;
- private publisher;
- tenant-scoped topic identity;
- same-origin Nginx SSE proxy;
- Docker Hub readiness;
- Turbo subscription credentials;
- browser boundary;
- реальний publish через `cos:web:realtime:smoke`.
