---
title: Маркетинг як окремий домен COS
description: Планування маркетингових кампаній і безпечне читання Meta Ads без рекламних мутацій.
status: draft
updated: 2026-11-19
kind: domain
version: 0.2.0
---

# Огляд домену Marketing

Marketing — окремий bounded context, що володіє рекламними планами, внутрішніми кампаніями, їхніми версіями, аудиторіями, креативами та спостереженнями рекламних провайдерів.

**Стан:** реалізовані кодові зрізи M0/M1 і M2a, очікує перевірок на staging і користувацького приймання. Домін встановлюється вимкненим за замовчуванням. Реальні платні операції заблоковано.

## Розподіл відповідальності

- **Marketing**: маркетингові кампанії й чернетки, рекламні аудиторії, креативи, імпорт Meta Campaign/AdSet/Ad та спостережені Insights.
- **Growth**: Market Universe, Signals, OpportunityCandidate, Qualification, Outreach, Response Routing і Growth Learning. Жодного перенесення Growth даних не відбулося.
- **Product**: товари, варіації, доступність і ціни. Marketing зберігає лише посилання та не оголошує їх перевіреними до приймання Product API.
- **Sales**: первинні факти лідів, угод і продажів.
- **Finance**: грошові ліміти, резервування, справжні витрати, платежі та фінансова звірка. Показник `spend` із Meta — не бухгалтерський Expense.

Внутрішня Marketing Campaign може мати декілька зовнішніх PaidMediaCampaign. Імпортовані рекламні об'єкти залишаються `EXTERNALLY_MANAGED`.

## Фактично додані можливості

П'ять typed capabilities M0/M1: `marketing.campaign.search`, `marketing.campaign.get`, `marketing.campaign.create`, `marketing.paid.account.list`, `marketing.paid.insights.query`. Для них діють Canonical Capability Gateway і перевірка tenant менеджера. Нових універсальних executor або CommandBus не створено.

На UI передбачено Overview, Campaigns, Campaign Workspace, Paid Media Accounts, Performance та Action Queue. Менеджер може створити внутрішню чернетку, під'єднати read-only Meta Ad Account через opaque Vault reference, імпортувати і переглядати provider observations.

Активація, створення рекламних кампаній у Meta, зміна бюджету та інші paid writes відсутні й навмисно блокуються. Для них потрібні Finance F1, незалежне погодження, canonical Workflow і провайдерний readback.

## M2a: Креативи та незалежний перегляд рекомендацій

Користувач у `/marketing/campaigns/{id}` створює Creative Draft, додає HTTPS destination, текст, CTA, локаль та необов'язковий Product reference або SHA-256 медіа. Модель зберігає `product_verification=PENDING_PRODUCT_ACCEPTANCE`, `destination_verification=UNVERIFIED` і `compliance_state=NOT_REVIEWED`. Вона **не підтверджує** доступність товару, безпечність URL чи політику Meta.

Усі правки Creative зберігаються як послідовність `marketing_creative_revisions` із content hash, actor і revision fencing. При зміні ревізії подані раніше, але ще не розглянуті рекомендації стають застарілими та не можуть бути прийняті.

Поради аналітика зберігаються в `marketing_analyst_proposals` із reference на конкретну Campaign і Creative revision, автором і явно зазначеними джерелами evidence. У M2a origin встановлює **сервер** як `HUMAN`, а evidence — `UNVERIFIED`: користувач не може видати людську нотатку за Hermes або verified provider insight. Інший менеджер може `ACCEPT` / `REJECT` із причиною; автор не може погодити власну пропозицію. Прийняття рекомендації **не змінює** статус Marketing Campaign і не запускає Meta/Finance операції.

Шість capabilities: `marketing.creative.create`, `marketing.creative.revise`, `marketing.creative.list`, `marketing.analyst.proposal.submit`, `marketing.analyst.proposal.list`, `marketing.analyst.proposal.review`. Вони працюють через той самий Canonical Capability Gateway; мутації `internal` і `human_only`. Відсутні token integration, Hermes autonomous invocation та паралельний executor.

Схема: `app/migrations/20261119_000154_marketing_planning_m2.sql`; інтеграційна перевірка: `tests/integration/marketing_m2_planning_mysql.php`. Модуль вимкнений за замовчуванням.

## Рівні готовності та обмеження

- **M0**: SQL, сервіси, контракти, початкові тести; приймання D2–D5 ще не доведене.
- **M1**: Meta read-only адаптер і збереження спостережень, але без підтвердженого реального рекламного акаунта та Ads Manager reconciliation.
- **M2a**: Креативи та людські evidence-пропозиції реалізовані з MySQL persistence, Workflow UI, двома незалежними учасниками й версіями. Справжній Product-read, перевірка товарної доступності, підтвердження policy/compliance, а також делегування Hermes **ще не інтегровані**. Додавання M2a не означає повного M2 acceptance.
- **F1/M3**: Finance F1 існує лише в окремому Draft PR #436, тому міждоменна інтеграція не прийнята; зовнішні рекламні дії залишаються заблокованими.
- **M4/M5**: мультиджерельна атрибуція, оптимізація й додаткові провайдери не реалізовані.

Початкова модель прав використовує `cos.tenant.access` та `cos.tenant.manage`; окремі `marketing.*` permissions необхідно додати до ширшого розгортання. У таблицях зберігаються лише непрозорі посилання на облікові дані, що розкриваються Platform Credential Vault під час дозволеного запиту.

Критерії подальшого приймання й контрольні етапи визначає `COS-MKT-TZ-001`.

