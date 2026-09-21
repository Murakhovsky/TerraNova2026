---
title: Безпека Web Platform
description: Канонічні security boundaries COS для CSRF, authorization, tenant isolation, sessions, uploads, CSP, downloads, rate limits, idempotency та locking.
status: active
updated: 2026-09-21
kind: architecture
---

# Безпека Web Platform

Wave 12.19 закриває security baseline Symfony Experience Platform.

Ця хвиля не створює окрему систему авторизації. Вона перевіряє та фіксує єдиний security path через Symfony Security, TenantContext, Application permissions і серверні mutation boundaries.

## Захист від міжсайтової підробки запитів

Session-backed mutations використовують `SessionCsrfValidator`.

Token:

- генерується після успішної authentication;
- зберігається у server-side session;
- передається через `X-CSRF-Token`, form field або JSON field;
- порівнюється через `hash_equals`.

Основні authenticated write controllers мають fail-closed CSRF перевірку.

Bearer/webhook transport не повинен удавати session mutation.

## Authorization і tenant isolation

Authorization складається з двох рівнів:

```text
Symfony access_control
        ↓
TenantContext / permissions
        ↓
Application / Domain policy
```

`TenantPermissionVoter` перевіряє, що subject належить поточній organization, перш ніж застосовувати permission.

Tenant id із browser payload не є authority.

## Сесії

Canonical cookie:

```text
COSSESSID
HttpOnly
Secure = auto
SameSite = Lax
```

Після login/register session id обов’язково мігрує через `session->migrate(true)`.

Logout invalidates server session.

CSRF token перевидається для нової authenticated session.

## Security headers і CSP

`SecurityHeadersSubscriber` додає:

- Content Security Policy;
- X-Content-Type-Options;
- Referrer-Policy;
- Permissions-Policy;
- X-Frame-Options;
- Cross-Origin-Opener-Policy.

HTML policy забороняє object embedding і framing COS, обмежує base/form origins та переводить insecure subresources на HTTPS.

Для Symfony AssetMapper кожен main request отримує криптографічний CSP nonce. Той самий nonce передається в `importmap('app', {'nonce': ...})`, тому inline importmap не потребує `script-src 'unsafe-inline'`.

Поточний AssetMapper також створює `data:application/javascript` entries для CSS imports, тому `script-src` дозволяє `data:` разом із `'self'` та request nonce. Це compatibility allowance саме для canonical AssetMapper runtime, а не дозвіл на довільний inline JavaScript.

`style-src 'unsafe-inline'` залишається тимчасовим compatibility allowance для поточного server-rendered UI. Послаблювати `script-src` до `unsafe-inline` заборонено.

## Обмеження частоти

Login використовує DB-backed `LoginRateLimiter`.

Алгоритм:

```text
identity hash
   ↓
SELECT ... FOR UPDATE
   ↓
window attempts
   ↓
temporary block
   ↓
Retry-After
```

Rate-limit identity хешується перед persistence.

Успішний login очищає bucket.

## Завантаження файлів

Наявний upload pipeline перевіряє:

- server upload error;
- byte limit;
- extension allowlist;
- detected MIME;
- file signature для Spatial formats;
- SHA-256 checksum.

Property media використовує temporary `.upload` staging перед final filename.

Business Documents зберігаються поза Web public root у `var/storage`.

Malware/AV scanning не симулюється. Якщо з’явиться production requirement для зовнішніх документів, scanner інтегрується як окремий quarantine adapter перед publication.

## Видача файлів

Private file content не має віддаватися прямим public path.

`PrivateDownloadResponseFactory` фіксує transport policy:

- attachment disposition;
- normalized filename;
- private/no-store cache;
- nosniff.

Authorization і tenant ownership перевіряються до виклику factory у Application/Web boundary.

## Ідемпотентність

Wave 12.19 не створює другу idempotency subsystem.

Канонічні mutation receipts уже існують у Property, Documents, Service та інших write paths.

Atomic `claim()` лишається authority для duplicate mutation protection.

HTTP transport повинен передавати стабільний idempotency key туди, де command contract цього вимагає.

## Блокування

Idempotency і locking вирішують різні задачі.

`MutationLockManagerInterface` визначає короткий critical-section lock для конкурентних mutations.

Production adapter використовує MySQL advisory locks:

```text
GET_LOCK
critical section
RELEASE_LOCK
```

Lock name формується з hash scope/resource і не містить business payload.

Lock завжди звільняється у `finally`.

## Межа browser

Browser не є authority для:

- permissions;
- tenant scope;
- CSRF validity;
- idempotency completion;
- lock ownership;
- upload trust;
- download authorization.

Stimulus/Turbo можуть передавати intent, але всі security decisions залишаються server-side.
