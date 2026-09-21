---
title: PWA Foundation
description: Канонічна installable online-first PWA основа COS без глобального offline business state.
status: active
updated: 2026-09-21
kind: architecture
---

# PWA Foundation

Wave 12.17 робить Symfony Experience Platform installable PWA surface, але не перетворює COS на offline-first ERP.

## Канонічна модель

```text
Symfony SSR / Turbo
        ↓
Web App Manifest
        ↓
Installable standalone surface
        ↓
Service Worker
        ├─ network-first navigation
        └─ static offline fallback
```

Business data, tenant state, Commands, approvals, documents та authenticated HTML не кешуються service worker'ом.

## Manifest

Canonical manifest:

```text
/manifest.webmanifest
```

Він визначає:

- application identity;
- `/admin` як start URL;
- root scope;
- standalone display;
- COS application icons;
- theme/background colors.

## Service Worker

Canonical worker:

```text
/sw.js
```

Worker перехоплює лише GET navigation requests.

Стратегія:

```text
navigation
   ↓
network
   ├─ success → server response
   └─ failure → /offline.html
```

Не дозволено:

- cache authenticated HTML;
- cache API responses;
- queue Commands;
- replay mutations;
- IndexedDB business state;
- Background Sync без окремої OfflineCapability;
- обходити authorization/tenant context.

## Offline fallback

`/offline.html` є статичною infrastructure page.

Вона прямо повідомляє, що COS online-first, і не імітує stale workspace або cached business data.

## Update strategy

Service worker не викликає `skipWaiting()` автоматично.

Коли нова версія встановлена і чекає activation, browser runtime генерує:

```text
cos:pwa-update-ready
```

UI може запропонувати користувачу оновлення.

Після явного UI intent browser генерує:

```text
cos:pwa-apply-update
```

Runtime надсилає worker message `SKIP_WAITING` і перезавантажує сторінку після `controllerchange`.

Це не дозволяє новій asset/runtime версії мовчки підмінити активний Workspace посеред операції.

## Static delivery

Nginx має окремі contracts для:

- `/sw.js` — no-cache/no-store;
- `/manifest.webmanifest`;
- `/offline.html`;
- `/icons/*`.

`Service-Worker-Allowed: /` фіксує root scope.

## Межа Wave 12.17

Ця хвиля не реалізує:

- local Domain stores;
- OfflineCommandQueue;
- conflict resolution;
- background sync;
- domain-specific offline forms;
- push notifications.

Selective Offline Capability належить окремим Domain requirements після platform foundation.

## Перевірка

CI перевіряє:

- manifest installability contract;
- standalone/root scope;
- icons;
- service worker root scope;
- network-first navigation;
- static offline fallback;
- explicit update activation;
- заборону browser business persistence/offline mutation queue;
- Nginx static delivery;
- runtime smoke `cos:web:pwa:smoke`.
