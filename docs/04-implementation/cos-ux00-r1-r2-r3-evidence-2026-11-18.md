---
title: UX-00 — Докази перевірки сценаріїв R1, R2 та R3
description: Результати браузерних і MySQL тестів Property та обмеження готовності інтерфейсу COS.
status: verified-in-ci
updated: 2026-11-18
kind: implementation
---

# UX-00 — Приймальні докази R1/R2/R3

**Кодова база:** COS `Murakhovsky/TerraNova2026`. **Тестовий коміт:** `45d16ec3e91e223352f0ddcc3d44b78779c091fa` із PR #432, GitHub Actions [Runtime 44298988705](https://github.com/Murakhovsky/TerraNova2026/actions/runs/44298988705).

**Середовище:** disposable Docker Compose MySQL і Symfony за адресою `http://127.0.0.1:8081` у GitHub Actions, 18 листопада 2026 року. **Не є тестом розгорнутого production чи AWS dev середовища.**

## 1. Фактично виконані перевірки

Браузерний Playwright `tests/browser/ux00_r1_r2_r3.mjs` завершився `ok: true` із вісьмома `PASS`:

| № | Результат | Сценарій |
|---:|---|---|
| 1 | PASS | R1: порожній Query, заборона зміни tenant scope через параметри |
| 2 | PASS | R2: неправильний CSRF заблоковано глобальним subscriber, HTTP 403 |
| 3 | PASS | R2: людина створила Property Asset через Web-форму; незалежне читання із Domain |
| 4 | PASS | R2: повторення тієї ж операції з тим самим ключем; рівно один рядок у native MySQL |
| 5 | PASS | R1: позитивний пошук по доступному Inventory, Query Get, пряме API-читання; чужий Asset і Inventory недоступні |
| 6 | PASS | R3: дубльований CSV відхилено |
| 7 | PASS | R3: CSV з двома елементами імпортовано у staging, digest і preview, анонімне читання відхилено |
| 8 | PASS | R3: створено Plan, підроблений digest відхилено, без незалежного погодження Run не стартує і native writes = 0 |

Тестовий Asset: `PROP-B9683CA4893010DB7C6A` у **тимчасовій** MySQL. Тестовий Batch: `bulk-5f33371d9f5e528c29189dcb`. Значення потрібні лише для аудиту виконання, вони не є об'єктами production.

Окремий job `Federation MySQL` з цього самого workflow завершився PASS для реального 10-об'єктного Property Bulk: tenant duplicate fencing, native readback, idempotent reconciliation та `Property federation approved ten Assets FULL MySQL E2E PASS`. Це підтверджує серверну реалізацію погодженого Workflow/Action/Receipt/Outcome **на ізольованих даних**, а не браузерний шлях від людського погодження до екрана результатів.

## 2. Інші обов'язкові перевірки на тестовому коміті

- `CI` — SUCCESS.
- `Frontend` — SUCCESS.
- `Documentation` — SUCCESS.
- `Runtime` — SUCCESS, включно з Symfony container, Web/Sales browser, Golden Eight, двома MySQL jobs.
- `L2 Recovery Smoke` — SUCCESS.
- Результати окремих браузерних тестів: [артефакт UX-00](https://github.com/Murakhovsky/TerraNova2026/actions/runs/44298988705/artifacts/14425456339), строк зберігання артефакту 14 діб за конфігурацією CI.

## 3. Дефекти, виявлені тестуванням

1. **HTTP 500 Capability Library:** `CapabilityLibraryController` не був публічним Symfony controller-service. Виправлено у PR #432 в `symfony/config/services.yaml`, додано регресійний source test.
2. **Повторне створення:** `PropertyWriteService::create` на повторному ключі повертав read-model `property.asset_id`, тоді як UI чекав `asset.asset_id`. Виправлено лише presentation-розбір результату, без зміни канонічної операції.
3. **Некоректне початкове припущення тесту:** `AccountAuthenticationService` обмежує login на `COS_ORGANIZATION_ID`. Тому тест використовує реальні native дані `foreign-tenant` і негативні tenant-bound запити, а **не імітує успішний другий login**.
4. **Неправильне первинне очікування HTTP CSRF:** існуючий `AuthenticatedSessionCsrfSubscriber` повертає 403 ще до контролера. Підтверджено захищеною відмовою.

## 3.1. Додаткова браузерна перевірка S4 (новий контрольний сценарій)

У цей PR додано позитивні браузерні перевірки **того самого Goal/Plan** у режимах `Result`, `Process`, `Expert`, а також перевірку відображення реального blocker після відмови старту без approval. Тест порівнює persisted `cos_actions`, `cos_workflow_runs` та native Property до/після перемикання режимів, робить три скриншоти в ізольованому CI та відмовляє у PASS при будь-якій прихованій мутації.

**Доказ PASS цих нових перевірок має бути взятий лише з запуску CI на коміті, що містить S4.** Попередній прогін `44298988705` засвідчує рівно 8 початкових тестів і не може бути доказом нового S4. Цей тест **не створює Run і не доводить завершений Run-перегляд**; повне R3 browser Approval→worker→outcome лишається окремим release gate.

## 3.2. Прогрес та посилання на результати R3 (нова реалізація)

Розширено наявний Property Bulk Workspace без додавання executor, policy чи нових команд:

- постійне, tenant-scoped посилання `/workspace/property/bulk?batch=…`;
- підрахунок `verified / total` на основі збережених item statuses та native Asset references;
- кожен підтверджений item показує посилання на незалежне читання `/workspace/capabilities?reference=…`;
- staged/planned/непідтверджені items **не отримують** посилання verified та банер успіху;
- невідповідність `batch.state=verified` і кількості підтверджених native Assets показує необхідність reconciliation, а не success;
- Playwright `R3.pending_deep_link_and_per_item_truth` перевіряє справжній пакет із двох позицій, нуль verified, статус кожної та стабільність URL.

Позитивний verified-item перехід у реальному браузері, незалежний worker та перехід Run у completed лишаються **непідтвердженими** без завершеного staging E2E. Новий тест не повинен успадковувати PASS від попередніх 13/13, потрібен окремий Runtime на новому SHA.

## 3.3. Незалежне погодження Plan через UI (нова перевірка R3-P)

Окремі браузерні сеанси двох користувачів **одного** tenant: власник надсилає Plan у Policy, незалежний менеджер ухвалює Approval через Control Center; штатний \`cos:queue:run\` виконує лише Federation Plan Approval Action. Тест перевіряє \`APPROVAL_REQUIRED\`, неможливість self-approval, ідентичність reviewer, рівно один Action attempt/receipt та \`cos_federation_plans.state=approved\`. Production-вимкнення зовнішніх Workflow-owned Actions лишається незмінним; нуль Property writes після погодження Plan є обов'язковим.

Це тестова CI fixture з opt-in Federation-модулем та активною policy **тільки у disposable MySQL**. Джерело \`tests/fixtures/ui/ux00_plan_approval.sql\` не є міграцією. PASS цього нового сценарію дозволено встановити лише після окремого Runtime на відповідному SHA. Він не підтверджує незалежне погодження зовнішнього Action, його worker admission або завершений Property Run.


## 4. Межі доведеної готовності## 3.4. Mobile acceptance extension (verified on exact-head CI)

Два нові Playwright assertions **PASS** на `9660df81a044e927bb3f6e6f64a7d070ed71569d`: [Runtime 44695313624](https://github.com/Murakhovsky/TerraNova2026/actions/runs/44695313624). **22/22 checks PASS**, всі п'ять CI workflow families SUCCESS; GHCR publish і AWS dev deploy SKIPPED.

Додано **два нові Playwright assertions** до наявного `tests/browser/ux00_r1_r2_r3.mjs`:

- `S1.mobile_verified_property_read_and_action_access`: viewport 390×844, реальний tenant-scoped Property readback, доступність кнопки створення без горизонтального обрізання та незмінність кількості native Asset rows.
- `S3.mobile_pending_items_and_deep_link`: viewport 390×844, два непідтверджені item statuses, нуль помилкових verified позначок, реальний перехід за постійним batch deep link.
- Артефакти `property-mobile-read.png` і `property-mobile-pending.png` робляться тільки на disposable localhost fixture.

**Evidence policy:** мобільні перевірки мають фактичне підтвердження з exact-head CI; staging R3 worker/outcome sign-off, справжні два tenant login та production лишаються поза межами цього PASS.

## 3.5. Перевірка обмеження доступу для користувача без повноважень

На окремому authenticated buyer/не-manager акаунті з поточним tenant і власним **валідним CSRF** перевіряємо відмову доступу до Capability Library та typed inventory, заборону `property.asset.create` через API і Web, і незмінність кількості native Property Asset rows. Це не анонімний користувач і не підроблений CSRF: API має бути відхилено на Symfony `ROLE_MANAGER` (403), а людську Web-форму окремо блокує canonical Gateway через `isManager/cos.tenant.manage` (303 з повідомленням `invalid`); native writes = 0.

Зміни в ізольованій disposable CI fixture та браузерному тесті, не в production Policy, Realm, Auth чи Domain Gateway. Статус **PENDING**, доки новий SHA не отримає фактичний PASS. Ліміт login 10 POST/300 s не обходиться; один допустимий retry суворо поважає серверний `Retry-After` тільки у GitHub localhost fixture.



| Критерій | Статус |
|---|---|
| R1 typed Query на тестовому default tenant, foreign-tenant data denial | CI PASS |
| R2 людський create Property через браузер, native receipt/readback і repeat-once | CI PASS |
| R3 браузер stage→preview→Plan, fail-closed відсутність approval | CI PASS |
| R3 backend повний approval→Workflow→Action→10 Property→receipts→outcome | Ізольований MySQL CI PASS |
| R3 browser approve→worker→per-item finished outcome | НЕ ПІДТВЕРДЖЕНО |
| Два одночасні логіни до різних tenant одного хоста | НЕ ПЕРЕВІРЕНО; поточний login прив'язаний до організації інсталяції |
| Негативні дії authenticated buyer / non-manager з валідним CSRF | PENDING новий CI; попередній 22/22 цього не перевіряв |
| Ручний operator signoff | НЕ ПІДТВЕРДЖЕНО |
| Розгорнутий staging і production / release | НЕ ПІДТВЕРДЖЕНО, без merge |

Машинна матриця `cos-ux00-experience-coverage.csv` у цьому PR уточнює **лише чотири** Property-контракти з окремими CI-статусами. Решта рядків залишаються `UNVERIFIED`, без екстраполяції зеленого CI на функціонал, який не тестувався.

**Release gate:** PR #432 залишити draft до окремого розгляду деплою. У поточному `.github/workflows/runtime.yml` merge у `main` може автоматично запускати AWS dev deployment. Право на production rollouts із цього звіту не випливає.

Відповідальні завдання: [UX-00 #428](https://github.com/Murakhovsky/TerraNova2026/issues/428), [Staging Acceptance #431](https://github.com/Murakhovsky/TerraNova2026/issues/431), [тестовий PR #432](https://github.com/Murakhovsky/TerraNova2026/pull/432).

## 3.6. Сумісність браузерних тестів із захистом авторизації

Окрема перевірка ролі додає одну законну спробу входу, тому після сумарних входів Sales/Property GitHub CI може досягти системного обмеження 10 POST за 300 секунд. На попередньому SHA UX-00 24/24 перевірки Property вже пройшли, але загальний Runtime зупинився в Golden Eight на очікуванні входу. Golden Eight тепер **один раз** поважає обмежений серверний `Retry-After` (1–300 секунд), виключно на localhost в GitHub Actions із явним прапорцем; production limiter та права не змінювалися. Це **не** сертифікація до повторного PASS усіх п'яти workflow на точному новому SHA.
