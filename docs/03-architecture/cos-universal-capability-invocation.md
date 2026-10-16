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
