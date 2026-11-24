---
title: Маркетинг як окремий домен COS
description: Планування маркетингових кампаній і безпечне читання Meta Ads без рекламних мутацій.
status: draft
updated: 2026-11-19
kind: domain
version: 0.1.2
---

# Огляд домену Marketing

Marketing — окремий bounded context, що володіє рекламними планами, внутрішніми кампаніями, їхніми версіями, аудиторіями, креативами та спостереженнями рекламних провайдерів.

**Стан:** початкова реалізація M0/M1, очікує перевірок на staging і користувацького приймання. Домін встановлюється вимкненим за замовчуванням. Реальні платні операції заблоковано.

## Розподіл відповідальності

- **Marketing**: маркетингові кампанії й чернетки, рекламні аудиторії, креативи, імпорт Meta Campaign/AdSet/Ad та спостережені Insights.
- **Growth**: Market Universe, Signals, OpportunityCandidate, Qualification, Outreach, Response Routing і Growth Learning. Жодного перенесення Growth даних не відбулося.
- **Product**: товари, варіації, доступність і ціни. Marketing зберігає лише посилання та не оголошує їх перевіреними до приймання Product API.
- **Sales**: первинні факти лідів, угод і продажів.
- **Finance**: грошові ліміти, резервування, справжні витрати, платежі та фінансова звірка. Показник `spend` із Meta — не бухгалтерський Expense.

Внутрішня Marketing Campaign може мати декілька зовнішніх PaidMediaCampaign. Імпортовані рекламні об'єкти залишаються `EXTERNALLY_MANAGED`.

## Фактично додані можливості

Шість typed capabilities початкового зрізу: `marketing.campaign.search`, `marketing.campaign.get`, `marketing.campaign.create`, `marketing.paid.account.list`, `marketing.paid.insights.query`, `marketing.paid.sync.history`. Для них діють Canonical Capability Gateway і перевірка tenant менеджера. Нових універсальних executor або CommandBus не створено.

На UI передбачено Overview, Campaigns, Campaign Workspace, Paid Media Accounts, Performance та Action Queue. Менеджер може створити внутрішню чернетку, під'єднати read-only Meta Ad Account через opaque Vault reference, імпортувати і переглядати provider observations.

Активація, створення рекламних кампаній у Meta, зміна бюджету та інші paid writes відсутні й навмисно блокуються. Для них потрібні Finance F1, незалежне погодження, canonical Workflow і провайдерний readback.

## Надійність імпорту та операційна черга

Meta read-only sync об'єднує імпорт Campaign/AdSet/Ad, Insights, `last_synced_at` і запис `SUCCEEDED` в **одну MySQL-транзакцію**. Невалідний рядок Insights або помилка посеред import викликає rollback без часткових campaign/metrics; окремий `tests/integration/marketing_meta_atomic_mysql.php` перевіряє це на справжній MySQL.

Якщо provider read або database import не завершилися, синхронізація залишає попередній успішний snapshot без змін, записує окрему `FAILED` спробу з **лише** санітизованим кодом `REMOTE_READ_FAILED` або `IMPORT_REJECTED`; raw API exception, access token чи provider URL не зберігаються. Якщо DB повністю недоступна, сам failure receipt також може бути недоступним; оператор отримує server error, але система не видає успіх. Дані Meta не модифікуються.

`/marketing/actions` показує останні спроби, посилання на account і операційну рекомендацію, включно зі станом `STALE_OR_NO_SYNC` після 24 годин від **останнього успішного синку**, без тверджень, що звіти за всі періоди свіжі. `marketing.paid.sync.history` доступний як tenant-safe read-only canonical Query. Ці receipts **не** є повним M3 external-mutation incident ledger.

## Рівні готовності та обмеження

- **M0**: SQL, сервіси, контракти, початкові тести; приймання D2–D5 ще не доведене.
- **M1**: Meta read-only адаптер і збереження спостережень, але без підтвердженого реального рекламного акаунта та Ads Manager reconciliation.
- **M2**: Product, креативні затвердження та Hermes Paid Media Analyst не реалізовані.
- **F1/M3**: Finance reservation та зовнішні рекламні дії заблоковано.
- **M4/M5**: мультиджерельна атрибуція, оптимізація й додаткові провайдери не реалізовані.

Початкова модель прав використовує `cos.tenant.access` та `cos.tenant.manage`; окремі `marketing.*` permissions необхідно додати до ширшого розгортання. У таблицях зберігаються лише непрозорі посилання на облікові дані, що розкриваються Platform Credential Vault під час дозволеного запиту.

Критерії подальшого приймання й контрольні етапи визначає `COS-MKT-TZ-001`.


## Читання джерельних рекламних спостережень (DAS-001)

`marketing.paid.spend.evidence.get` є tenant-scoped read-only Capability для перегляду збереженого source snapshot Meta на рівні кампанії. Сервіс перевіряє tenant, рекламний акаунт і snapshot identifier, повертає source hash, revision, window, timezone, currency та reported spend. Відповідь позначена `MARKETING_DB_SNAPSHOT` і `finance_expense_confirmed=false`.

Цей контракт **не** підтверджує рахунок, витрату Finance, факт оплати або незалежну звірку з Meta. Будь-яке перенесення до Finance потребує окремого погодженого cross-domain port, source pinning та Finance-owned reconciliation.
