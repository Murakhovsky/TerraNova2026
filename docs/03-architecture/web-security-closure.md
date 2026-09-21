---
title: Закриття Web-безпеки
description: Канонічні правила CSRF, авторизації, tenant isolation, sessions, uploads, CSP, downloads, rate limits, idempotency та locking для Web Platform COS.
status: active
updated: 2026-09-21
kind: architecture
---

# Закриття Web-безпеки

Wave 12.19 не створює окрему security-систему для UI. Вона фіксує, що Symfony Web Platform використовує ті самі identity, tenant, permissions та write contracts, що й решта COS, і додає відсутні transport-level guards. Це відповідає переліку Wave 12.19 у ТЗ. fileciteturn239file2L1-L1

## Захист запитів із сесією

Authenticated browser write запити:

```text
POST / PUT / PATCH / DELETE
        ↓
authenticated COS session?
        ↓
bearer token? ── yes → token security
        │
        no
        ↓
protected Web/API path?
        ↓
SessionCsrfValidator
        ↓
Application write path
```

CSRF token створюється після успішного login/register разом із session migration.

Token приймається через:

- `X-CSRF-Token`;
- `csrf_token` form field;
- `csrf_token` JSON field.

Public webhook та bearer-token transport не повинні залежати від browser CSRF.

## Авторизація і tenant isolation

Authorization не визначається Twig, Stimulus або visibility кнопки.

Канонічний порядок:

```text
authenticated identity
        ↓
TenantContext
        ↓
organization membership
        ↓
TenantPermissionVoter / Application policy
        ↓
Command / Query
```

`TenantPermissionVoter` відхиляє resource, якщо його organization не збігається з поточним tenant context.

## Сесії

Канонічна browser session:

- `COSSESSID`;
- HTTP-only cookie;
- secure cookie в HTTPS runtime;
- SameSite=Lax;
- session cookie без persistent lifetime;
- server-side max lifetime 8 годин;
- session id migration після authentication;
- invalidation на logout;
- новий CSRF secret після establish session.

## Заголовки безпеки

Symfony responses отримують централізовано:

- Content Security Policy;
- `X-Content-Type-Options: nosniff`;
- `X-Frame-Options: DENY`;
- strict referrer policy;
- Permissions Policy;
- Cross-Origin-Opener-Policy;
- HSTS на HTTPS.

Поточний CSP ще містить `unsafe-inline` та `unsafe-eval` для сумісності з існуючими browser islands і htmx runtime. Це свідомий compatibility debt, а не цільовий стан. Після reference Sales cutover його треба звузити через nonce/hash policy та видалення eval-залежності.

## Обмеження частоти

Platform rate limiter захищає:

```text
auth.login
auth.register
public.analytics
spatial.events
authenticated.write
```

Лічильник оновлюється під MySQL advisory lock, щоб паралельні requests не обходили fixed-window counter.

Rate-limit response:

```text
429 Too Many Requests
Retry-After
X-RateLimit-Limit
X-RateLimit-Remaining
Cache-Control: no-store
```

## Блокування

`MySqlAdvisoryLock` є infrastructure primitive для коротких критичних секцій.

Він використовує:

```text
GET_LOCK
critical section
RELEASE_LOCK
```

Lock не замінює Domain transaction або idempotency. Для business mutation правильна модель:

```text
idempotency key
   +
transaction
   +
domain concurrency rule
   +
lock only when serialized access is actually required
```

## Idempotency

Wave 12.19 не створює другий idempotency layer.

У COS уже існують idempotency contracts у canonical write paths, зокрема:

- Property writes;
- Documents runtime;
- Sales communications;
- queues та asynchronous operations.

Security gate перевіряє, що ці write paths не втратили idempotency contract.

## Завантаження файлів

Spatial upload більше не переходить напряму з PHP temporary path у public storage.

Pipeline:

```text
PHP temp
  ↓
private quarantine
  ↓
size
  ↓
signature
  ↓
MIME
  ↓
checksum
  ↓
public/domain storage
  ↓
async validation / processing
```

Quarantine directory створюється з private permissions, а temporary file видаляється при failure/destruction.

Malware scanner не симулюється. Якщо для конкретного deployment потрібен ClamAV або зовнішній scanner, він має бути окремим adapter між quarantine і release.

## Завантаження файлів користувачу

`SecureDownloadResponseFactory` задає transport contract:

- canonical readable path;
- sanitized filename;
- attachment disposition;
- private + no-store cache policy;
- explicit content type;
- `nosniff`.

Resource authorization виконується до створення download response через Application/Tenant policy. Factory не перетворює шлях до файлу на право доступу.

## Межі

Wave 12.19 не робить:

- browser permission model джерелом authorization;
- antivirus без реального scanner runtime;
- глобальний lock на кожну mutation;
- окремий tenant id із client payload;
- localStorage session;
- permissive cross-origin API;
- frontend-only idempotency.

Результат хвилі: Web transport має централізовані security guards, а business authorization, tenant scope та idempotency лишаються у канонічних COS layers.
