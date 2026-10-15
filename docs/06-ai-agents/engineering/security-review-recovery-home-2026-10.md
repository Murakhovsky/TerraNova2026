---
title: Адресне відновлення архітектури головної сторінки COS
description: Реєстр невирішених SEC-001–003, фактичні файли-докази та умови незалежної повторної перевірки.
status: active
updated: 2026-10-15
kind: guide
---

# Адресне відновлення архітектури головної сторінки COS

Feature ID: `b1673b25-1859-4e68-8470-8d1fc0a5b349`  
Workflow ID: `c2e88072-6791-4385-986d-e6c099093774`  
Pinned review revision: `ec797294b3535eeb3cc89b0fbd79d33cb575708a`  
Architecture Decision v3: **REJECTED**, not development authorization.  
Security review: **REQUEST_CHANGES**, based on an earlier architecture proposal; explicit reviewed architecture artifact ID/hash absent in the legacy report.

## Як використовувати

Це read-only evidence dossier для **цільового доопрацювання**, а не нове схвалення.
Не запускати заблокований workflow автоматично. Після застосування recovery-контракту
і контрольованої підготовки джерел потрібне свідоме відновлення поточного workflow;
після результату Architect потрібна незалежна перевірка Security Specialist.
Звіт, побудований лише із summary без файлів репозиторію, не може сам по собі
довести ані успішне проходження захисту, ані існування вразливості.

## Встановлені факти за pinned revision

- `symfony/config/routes.yaml`: `cos_symfony_home` є `/` з методами GET, HEAD;
  `cos_web_auth_login` → `/auth/login`; `cos_web_cabinet` → `/cabinet`.
- `symfony/src/Web/PublicSite/HomeController.php`: нинішня головна сторінка
  **не персоналізована**, віддає `Cache-Control: public, max-age=60`.
  Це не підтверджує витік між сесіями, але така політика несумісна з майбутнім
  session-dependent CTA без зміни кешування.
- `symfony/public/sw.js`: navigation GET виконується `fetch(request)`; тільки
  після network failure повертається **статичний** `offline.html`. HTML навігації
  у Cache Storage не записується. Це не доводить поведінки CDN, браузерного
  HTTP-кешу або уже розгорнутих старих service worker.
- `symfony/assets/pwa_runtime.js`: реєстрація `/sw.js` зі scope `/`.
- `docker/symfony/nginx/default.conf`: `/sw.js` має `no-cache, no-store`;
  HTML root віддається через FastCGI. Поведінка зовнішнього proxy/CDN
  потребує runtime-перевірки.
- `symfony/config/packages/security.yaml` і
  `symfony/src/Security/SessionAuthenticator.php`: root не входить до
  authenticated firewall; захищені destinations мають власну перевірку.
- `symfony/src/Security/CosIdentityLoader.php`: канонічне завантаження
  користувача через `IdentityResolverInterface`; ідентифікатор має формат
  user-ID + encoded organization, із перевіркою допустимих значень.
  Наявність session keys сама по собі не дорівнює автентифікації.
- `symfony/src/Web/Auth/AuthPageController.php`: login/logout та session
  встановлюються окремо, protected Cabinet також використовує
  `Cache-Control: no-store, private`.
- `symfony/src/Web/PublicSite/PublicCosController.php`: при помилках
  повертає 503 із загальним текстом, але `error_log` записує message винятку.
  Цей шлях потребує security-review sanitized logging.
- `symfony/templates/experience/public/home.html.twig`: поточна публічна
  композиція використовує перевірювані статичні внутрішні destinations,
  не здійснює session-dependent CTA.
- `tests/history/architecture/web_experience_wave13_public_home.php`,
  `tests/history/architecture/web_experience_wave12_pwa.php`,
  `tests/architecture/web_access_diagnostics.php`: контрактні джерела тестів.
  **Факт виконання runtime/browser тестів у межах цього dossier відсутній.**

## Findings та завдання

### SEC-001 · MAJOR · Cache isolation

**Стан: OPEN / EVIDENCE_PARTIAL.** Код worker network-first, але CDN/HTTP,
HEAD, exception responses і старі root caches не доведені тестами.

ACT-001: до зміни HTML підготувати документований контракт `private, no-store`
для персоналізованої root-сторінки на 200, 503 та HEAD; перевірити HTTP headers
у всіх станах, session A/B, anonymous, service worker online/offline, зовнішній CDN
і наслідки раніше публічно закешованої `/`. Не змінювати shared cache policy
каталогу без окремого архітектурного рішення.

**Критерій closure:** виконувані тести + фактичні HTTP headers на reviewed revision,
жодного повторного використання session-dependent HTML іншою сесією.

### SEC-002 · MEDIUM · Identity interface

**Стан: OPEN / EVIDENCE_PARTIAL.** Канонічний loader знайдено, але публічний
root не є authenticated firewall boundary. Не використовувати `app.user` як
доведений root identity і не інтерпретувати просто `tn_auth_user_id` як
завершену авторизацію.

ACT-002: узгодити presentation-only інтеграцію зі справжнім
`CosIdentityLoader` / `IdentityResolverInterface`; перевірити anonymous,
valid, revoked, malformed, cross-organization, provider failure; жодних
нових grants, сесійних записів чи tenant fallback; маршрути `/auth/login`,
`/cabinet` залишити із власною авторизацією.

**Критерій closure:** integration/browser тести для edge cases та tenant-isolation
із правильними статусами й без зміни firewall.

### SEC-003 · MEDIUM · Rendering/observability

**Стан: OPEN / IMPLEMENTATION_TESTS_PENDING.** Головна сторінка ще не реалізована
за цим feature. Без перевірки виконаної mutation неможливо закрити finding.

ACT-003: контекстне Twig escaping, перевірені назви routes та непідконтрольні
користувачеві redirects, тільки підтримувані параметри каталогу; тестувати
malicious content/URL, 404/503, sanitized logs без session IDs, tenant identifiers
та exception detail. При розширенні root іншими Content queries не дублювати каталог.

**Критерій closure:** executable DOM/browser security + failure tests на
фактичній реалізації, а не декларація в Architecture Decision.

## Умови Architecture Gate

1. Architect отримує саме Security Specialist findings та `ACT-001..003`,
   а не лише попередній Architecture Decision.
2. `SEC-001` до реалізації має повний security-reviewable plan і доказ
   відповідності механізмів caching; невстановлені runtime обмеження
   лишаються blocking, а не «мовчазно схвалені».
3. `SEC-002` до розробки має конкретний integration contract.
4. `SEC-003` зберігається як обов'язкова перевірка після реалізації.
5. Лише незалежний Security Specialist може підтвердити закриття findings.
6. Якщо немає достатнього evidence або незалежної перевірки, дозволу на
   Developer/merge/deploy немає. Не підміняти це Human Gate чи новим LLM-циклом.

## Recovery pipeline

`SECURITY_REVIEW_REPORT → Targeted finding context → Revision-bound evidence
→ Revised ARCHITECTURE_DECISION → Independent Security Specialist
→ (only on APPROVED) Developer → Independent QA`.

Операторська дія для production workflow буде потрібна окремо; цей документ
не змінює статус workflow у БД.
