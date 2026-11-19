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

## 4. Межі доведеної готовності

| Критерій | Статус |
|---|---|
| R1 typed Query на тестовому default tenant, foreign-tenant data denial | CI PASS |
| R2 людський create Property через браузер, native receipt/readback і repeat-once | CI PASS |
| R3 браузер stage→preview→Plan, fail-closed відсутність approval | CI PASS |
| R3 backend повний approval→Workflow→Action→10 Property→receipts→outcome | Ізольований MySQL CI PASS |
| R3 browser approve→worker→per-item finished outcome | НЕ ПІДТВЕРДЖЕНО |
| Два одночасні логіни до різних tenant одного хоста | НЕ ПЕРЕВІРЕНО; поточний login прив'язаний до організації інсталяції |
| Перевірки viewer-role негативних дій, ручний operator signoff | НЕ ПІДТВЕРДЖЕНО |
| Розгорнутий staging і production / release | НЕ ПІДТВЕРДЖЕНО, без merge |

Машинна матриця `cos-ux00-experience-coverage.csv` у цьому PR уточнює **лише чотири** Property-контракти з окремими CI-статусами. Решта рядків залишаються `UNVERIFIED`, без екстраполяції зеленого CI на функціонал, який не тестувався.

**Release gate:** PR #432 залишити draft до окремого розгляду деплою. У поточному `.github/workflows/runtime.yml` merge у `main` може автоматично запускати AWS dev deployment. Право на production rollouts із цього звіту не випливає.

Відповідальні завдання: [UX-00 #428](https://github.com/Murakhovsky/TerraNova2026/issues/428), [Staging Acceptance #431](https://github.com/Murakhovsky/TerraNova2026/issues/431), [тестовий PR #432](https://github.com/Murakhovsky/TerraNova2026/pull/432).
