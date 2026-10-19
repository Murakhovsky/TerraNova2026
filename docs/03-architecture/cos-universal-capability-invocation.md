---
title: Канонічна бібліотека можливостей COS
description: Єдиний каталог команд і запитів із контрольованим доступом для користувачів та автоматизацій.
status: pilot
kind: architecture
updated: 2026-10-16
---

# Канонічна бібліотека команд COS

Основне джерело метаданих — наявний \`CanonicalCapabilityCatalog\`, що читає
\`capability_contracts\` із маніфестів доменів. Новий шлюз є лише шаром
перевірки й маршрутизації. Він не створює окремого \`CommandBus\`,
виконавця \`Action\` або сховища \`Workflow\`.

## Каталог і рівні готовності

Машинні маршрути: \`GET /api/v1/capabilities\` та
\`GET /api/v1/capabilities/{id}\`. Робочий інтерфейс менеджера:
\`/workspace/capabilities\`.

Статуси мають точне значення:

- \`unavailable\`: опис без перевіреного виконання, неактивний модуль або брак дозволу;
- \`read_only\`: зареєстрований запит до наявного Domain \`QueryBus\`;
- \`direct_human\`: внутрішня транзакційна команда для авторизованого користувача;
- \`workflow_approval_required\`: реальний \`Action\` потребує погодженого
  \`Platform Workflow\` і не може виконуватися напряму через шлюз.

## Перший інтегрований сценарій Property

Зареєстровано чотири можливості: `property.asset.create`,
`property.asset.update`, `property.asset.get`, `property.asset.search`.
Команди й запити використовують наявні `CommandBus` і `QueryBus` Property. Створення об'єкта проходить через
канонічну транзакцію Property, наявний механізм ідемпотентності
та журнал аудиту. Автоматична публікація Listing відсутня.

Інтерактивний API:

\`POST /api/v1/capabilities/property.asset.create/invoke\`

Необхідна автентифікована сесія менеджера, чинний CSRF-токен,
заголовок \`X-Idempotency-Key\` і JSON-об'єкт із \`type_code\` та \`city\`.
Tenant, виконавець і correlation визначаються сервером. Клієнту
заборонено підміняти ці ідентичності в параметрах. Повторна команда
проходить через існуючі квитанції Property.

## Безпека та межі реалізації

Ця зміна **не надає AI або CRON права змінювати бізнес-дані**.
Автоматизовані операції потребують окремого погодженого плану,
\`Platform Workflow\` та перевірених \`Kernel Action / Policy / Approval\`.
Джерело виклику призначає HTTP-адаптер, а не JSON. Захист сесій
залишається відповідальністю наявної системи автентифікації.

Це перший робочий зріз, а не завершення всього пакета P0.
Надалі потрібні: перевірка контрактів усіх доменів, валідація вихідних схем
та розширене тестування реальних результатів, сервісні ідентичності для AI/CRON,
підключення довільних команд до погоджених Workflow, UX масового
імпорту, незалежні бізнес-докази, інтеграційні MySQL-тести та
контрольований production rollout. Цей пакет не виконує міграцій SQL
і не активує автоматичне виконання.

## Подальший розвиток

Перед виконанням зареєстрованої Domain-операції Gateway перевіряє
локальний JSON-контракт через той самий `CapabilityJsonInputValidator`,
який використовується при погодженні Goal. Відхиляються невідомі поля,
підміна tenant/actor, некоректні типи, завеликі дані, невалідні числові
межі та спроби змінити ідентичність Property. Підтвердження записів
залишаються під контролем власника Domain.

Приклади викликів для автентифікованого менеджера:

- `property.asset.create`: `{"type_code":"house","city":"Львів","area_total":125}`
- `property.asset.get`: `{"reference":"PROP-..."}`
- `property.asset.update`: `{"reference":"PROP-...","patch":{"area_total":127}}`
- `property.asset.search`: `{"q":"Львів","page":1,"per_page":10}`

Метод `POST /api/v1/capabilities/{id}/invoke` залишається
інтерактивним та CSRF-захищеним. Масовий імпорт із файлів,
автоматичне керування Goal та виклики AI/CRON **ще не увімкнені**.

## Ручне тестування через інтерфейс

Маршрут `/workspace/capabilities` містить форму для однієї
`property.asset.create`. Кнопка передає команду через наявний Gateway
з обов'язковим CSRF і унікальним ключем ідемпотентності. При поверненні
на сторінку система повторно читає Property за канонічним ID. Тільки
успішне незалежне читання показує повідомлення про підтверджений запис.

Увага: це створення Property Asset, а не Inventory або Listing.
Із цієї форми не виконуються bulk-імпорт, CRM, публікація чи AI/CRON.

## Масовий імпорт Property: P0

Інтерфейс: `/workspace/property/bulk`.
API з автентифікованою сесією і CSRF:
`POST /api/v1/property/bulk` для staging масиву `items` (1–10),
`GET /api/v1/property/bulk/{batchId}` для незмінного перегляду,
`POST /api/v1/property/bulk/{batchId}/propose` з `{"digest":"..."}`
для створення Goal і Plan. Після цього людина в Goals запускає
штатний процес незалежного погодження Plan, створення Run і погодження
кожного Action. Жодне з цих API не запускає Workflow напряму.

`property.bulk.create` реалізовано як Domain-owned Kernel Action, де
`execution_binding=action:property.bulk.create`,
`approval_policy=required`, `idempotency=required`, `retry.max=1`.
Тільки канонічна черга Action може виконувати власний Property handler.
Він використовує вже існуючий `PropertyWriteService`, а
не напряму вставляє записи у native таблиці Property.
Невизначена часткова операція блокує автоматичний retry та потребує
операторського узгодження receipts. Staging таблиці не містять
жодних технічних `Workflow Run/Step`.

Перевірка дублікатів консервативна: повторні адреси в самому пакеті
відхиляються, а existing native Property перевіряється через tenant-scoped
Property search/read. Неоднозначне зіставлення або переповнення вибірки
блокує обробку. Це **не** повний геокодер або fuzzy entity resolution;
некоректно записані адреси повинні проходити ручний review.

Показник `property.bulk_assets_verified` обчислюється окремим
Domain-backed Run evidence provider: перевірений Action receipt,
immutable approved Plan, завершений Run і фактично існуючі native
Property Assets. Статус Workflow сам по собі не є бізнес-результатом.

## Машинні запити AI/CRON, без виконання від імені людини

`POST /api/v1/capability-intents/property-bulk` приймає тільки
`{"capability_id":"property.bulk.create","items":[...]}`, не більше
10 об'єктів. Машинні headers:

- `X-COS-Automation-Origin`: `ai` або `cron`;
- `X-COS-Automation-Timestamp`: unix time у секундах;
- `X-COS-Automation-Nonce`: випадкова ASCII послідовність 16–80 знаків;
- `X-COS-Automation-Signature`: lowercase hex HMAC-SHA256.

Підписуються точні байти:
`origin + "\n" + timestamp + "\n" + nonce + "\n" + raw_request_body`.
Два різні ключі (`COS_AI_CAPABILITY_INTENT_SECRET` і
`COS_CRON_CAPABILITY_INTENT_SECRET`), не менш як 32 байти кожний.
`COS_AUTOMATION_TENANT_ORGANIZATION` задає **серверну**
організацію. Порожні секрети й org блокують виклики за замовчуванням.
Підпис чинний не більше 300 секунд. Повторний nonce заборонений.
Тіло запиту не може вказувати tenant або права.

Машина зберігає тільки `pending_review` у
`cos_capability_automation_intents`. Ніякого Action/Workflow старту.
Менеджер відкриває `/workspace/capabilities/intents`, перевіряє
дані й з CSRF приймає їх у Property staging. Далі працюють ті самі
перевірки дублікатів та незалежні Plan/Action approvals у Goals.
Тому джерела AI та CRON тепер підключені до **одного шляху Plan →
Platform Workflow**, не маючи повноважень запускати мутації напряму.

Цей режим не є повністю автономним безлюдним виконанням. Автоматичне
продовження затвердженого Run від сервісного облікового запису
потребує окремої керованої делегації й чинної Identity/Policy,
якої ця версія не видає. Це навмисне обмеження безпеки.

### Конкурентний захист від дублікатів

Під час propose Plan кожний normalized Property fingerprint отримує
унікальний tenant-scoped claim у `cos_property_bulk_identity_claims`.
Дві паралельні транзакції не можуть успішно зарезервувати той самий
fingerprint. До approved Action виконавець повторно порівнює
параметри кожного item із незмінним SHA-256 batch digest і перевіряє
ownership claim. Claim зберігається після успіху; автоматично
звільняти його після ambiguous partial write заборонено. Це не
замінює загальну dedupe-policy для Property, записаних поза Bulk.

### CSV для користувача

`/workspace/property/bulk` приймає UTF-8 CSV (до 64 КіБ, 1–10 рядків)
через `POST /workspace/property/bulk/upload` із session-CSRF.
Excel можна експортувати як CSV UTF-8. Перший рядок:
`type_code,city,address,area_total` (перші три обов’язкові,
площа необов’язкова). Підтримуються Excel CSV `;`, `,` і табуляція.
Неочікувані колонки, формули, від'ємні числа, більше десяти
рядків та не-UTF-8 відхиляються. Парсер **не** здійснює запис
у Property: він передає дані у той самий `PropertyBulkIntakeService::stage`.
Нативний формат XLSX поки не приймається: файл слід експортувати
як CSV UTF-8. Це виключає небезпечний неаудитований Excel-парсер.

### Перевірка десяти нативних об'єктів у MySQL

`tests/integration/property_bulk_native_ten_assets_mysql.php`, виконується
в ізольованому GitHub Actions MySQL 8.4 під
`COS_TEST_PROPERTY_BULK_NATIVE=1`. Тест:
1. створює tenant-scoped staging із десяти об'єктів;
2. змінює staged JSON та вимагає відмови через digest/fingerprint;
3. формує ten identity claims, імітує вже перевірений стан Plan;
4. виконує **справжній PropertyWriteService** і читає десять
   `tn_property_assets` через PropertyReferencePort;
5. перевіряє ідемпотентне повторне звіряння та відмову при підміні digest.

У цьому тесті лише події/Audit приймають ізольовані test-local sinks.
Стан Plan для Domain-операції імітується: це **не** справжній
end-to-end тест створення Plan, незалежних Approval і Platform
Workflow. Цю частину додатково перевіряє `tests/integration/federation_property_bulk_approved_e2e_mysql.php`:
створення справжнього Goal/Plan; перевірка незалежного Plan Approval
з тестовою persisted evidence; запуск одного `Platform Workflow`;
запит і незалежне погодження `Kernel Action` через реальний Approval
repository; один worker; десять `tn_property_assets`; контрольований
receipt checkpoint, завершення Run та незалежна Goal Evaluation.
Негативні перевірки охоплюють передчасний worker, повторний start,
повторний Action та підміну одного native receipt після успіху.
Перший Plan Approval у тесті створюється як спеціальний SQL-fixture,
а не через UI: повноцінний браузерний acceptance із двома людьми
та production deploy залишаються окремими контрольними воротами.

### Перевірка походження результату Property

`PropertyBulkRunOutcomeProvider` повторно звіряє незмінний SHA-256
пакета, fingerprint кожного рядка, прив'язаний tenant identity claim,
native receipt key, фактичну адресу, тип і наявність десяти незалежних
Property Assets. Підміна `payload_json`, статусу або
`native_asset_id` в staging не може сама по собі виконати Goal.
Потрібне також незалежне підтвердження Action/Run у Platform Workflow.
