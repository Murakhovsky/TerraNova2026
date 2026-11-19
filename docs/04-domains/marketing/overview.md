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

Сім typed capabilities початкового зрізу: `marketing.campaign.search`, `marketing.campaign.get`, `marketing.campaign.create`, `marketing.paid.account.list`, `marketing.paid.insights.query`, `marketing.paid.sync.history`, `marketing.paid.spend.evidence.get`. Для них діють Canonical Capability Gateway і перевірка tenant менеджера. Нових універсальних executor або CommandBus не створено.

На UI передбачено Overview, Campaigns, Campaign Workspace, Paid Media Accounts, Performance та Action Queue. Менеджер може створити внутрішню чернетку, під'єднати read-only Meta Ad Account через opaque Vault reference, імпортувати і переглядати provider observations.

Активація, створення рекламних кампаній у Meta, зміна бюджету та інші paid writes відсутні й навмисно блокуються. Для них потрібні Finance F1, незалежне погодження, canonical Workflow і провайдерний readback.

## Надійність імпорту та операційна черга

Meta read-only sync об'єднує імпорт Campaign/AdSet/Ad, Insights, `last_synced_at` і запис `SUCCEEDED` в **одну MySQL-транзакцію**. Невалідний рядок Insights або помилка посеред import викликає rollback без часткових campaign/metrics; окремий `tests/integration/marketing_meta_atomic_mysql.php` перевіряє це на справжній MySQL.

Якщо provider read або database import не завершилися, синхронізація залишає попередній успішний snapshot без змін, записує окрему `FAILED` спробу з **лише** санітизованим кодом `REMOTE_READ_FAILED` або `IMPORT_REJECTED`; raw API exception, access token чи provider URL не зберігаються. Якщо DB повністю недоступна, сам failure receipt також може бути недоступним; оператор отримує server error, але система не видає успіх. Дані Meta не модифікуються.

`/marketing/actions` показує останні спроби, посилання на account і операційну рекомендацію, включно зі станом `STALE_OR_NO_SYNC` після 24 годин від **останнього успішного синку**, без тверджень, що звіти за всі періоди свіжі. `marketing.paid.sync.history` доступний як tenant-safe read-only canonical Query. Ці receipts **не** є повним M3 external-mutation incident ledger.

## Finance-facing readback: перевірені за ключем дані Marketing

`marketing.paid.spend.evidence.get` є **read-only Query** для одного збереженого Campaign-grain Meta Insights snapshot, ідентифікованого через `account_id` + `source_snapshot_ref`. Доступ з боку UI/AI/API підкоряється чинним tenant manager permissions та canonical Capability Gateway.

Результат включає тільки Finance-сумісний evidence payload: Marketing account ref, stable source snapshot ref, `grain=campaign`, зовнішній Campaign ID, незмінний reporting window, timezone, currency, attribution hash, provider source hash, `source_revision`, source status та точний десятковий `reported_spend_decimal`. Query не повертає raw Meta metrics, access token, Vault reference або фінансові об'єкти.

Запит читає snapshot **разом із account** із перевіркою tenant, account binding, Meta provider, `READ_ONLY` connection, однакових валют і timezone. Невідома/чужа/застаріла прив'язка відхиляється. Provider row updates змінюють revision і hash, але зберігають snapshot ID; Finance зможе застосувати його власну перевірку ідемпотентності.

Важлива різниця: `readback_status=MARKETING_DB_SNAPSHOT` підтверджує **тільки наявність збереженого запису в Marketing**. Не доводить актуальну інформацію Meta Ads Manager, виставлену фактуру, оплату чи перевірене походження провайдерних даних. Вивід `finance_expense_confirmed=false`. Наразі **немає автоматичного Finance ingestion**: Finance #436 окремий Draft PR, і цей Query не надає права запису у Finance чи списання бюджету.

Реальний MySQL regression: `tests/integration/marketing_meta_atomic_mysql.php`. Контракт: `resources/contracts/marketing/spend-evidence-get-{input,output}.schema.json`.

## Рівні готовності та обмеження

- **M0**: SQL, сервіси, контракти, початкові тести; приймання D2–D5 ще не доведене.
- **M1**: Meta read-only адаптер і збереження спостережень, але без підтвердженого реального рекламного акаунта та Ads Manager reconciliation.
- **M2**: Product, креативні затвердження та Hermes Paid Media Analyst не реалізовані.
- **F1/M3**: Finance reservation та зовнішні рекламні дії заблоковано.
- **M4/M5**: мультиджерельна атрибуція, оптимізація й додаткові провайдери не реалізовані.

Початкова модель прав використовує `cos.tenant.access` та `cos.tenant.manage`; окремі `marketing.*` permissions необхідно додати до ширшого розгортання. У таблицях зберігаються лише непрозорі посилання на облікові дані, що розкриваються Platform Credential Vault під час дозволеного запиту.

Критерії подальшого приймання й контрольні етапи визначає `COS-MKT-TZ-001`.

