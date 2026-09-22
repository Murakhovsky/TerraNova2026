---
title: Поточний стан COS
description: Фактичний продуктовий та архітектурний обсяг поточного main.
status: active
updated: 2026-09-22
kind: product
---

# Поточний стан COS

Ця сторінка описує **фактично реалізований стан (AS-IS)** поточного `main`, а не дорожню карту або бажаний майбутній стан.

## Базовий виконуваний стан

| Компонент | Версія | Поточний стан |
| --- | --- | --- |
| Kernel | `0.11.9` | виконуваний контракт платформи |
| Sales | `0.8.6` | повний модуль середовища виконання та еталонний домен |
| Growth | `0.15.0` | Opportunity Intelligence + Signal Intake + Engagement Intelligence + closed-loop Sales outcome learning; schema `0.15.0`; disabled by default |
| Diagnostic | `0.6.1` | встановлюваний модуль із маршрутами API, споживачем подій і постійним станом |
| Property | `0.12.0` | встановлюваний модуль із канонічними записами Asset/Inventory/Listing, сумісним представленням, аналітикою, інтелектом і зовнішньою взаємодією |
| Finance | `0.1.0` | встановлюваний V1 skeleton; runtime і persistence навмисно відкладені |
| Procurement | `0.1.0` | встановлюваний V1 skeleton; runtime і persistence навмисно відкладені |
| Service | `0.2.0` | активний runtime Request → Ticket → Assignment/SLA → Escalation → Resolution → Close; Symfony API, persistence, events, audit та idempotency |
| Construction | `0.1.0` | встановлюваний V1 skeleton; runtime і persistence навмисно відкладені |
| Hr | `0.1.0` | встановлюваний V1 skeleton; runtime і persistence навмисно відкладені |
| Real_estate | `0.2.0` | активний brokerage runtime поверх Property: Opportunity → Property Match → Offer → Viewing → Reservation; Symfony API, persistence, events, audit та idempotency |

Машиночитані факти: [довідник модулів і можливостей](../12-reference/module-capabilities.md).

## Growth: Opportunity Intelligence

Growth `0.15.0` розвиває окремий bounded context для **FIND VALUE**. Canonical runtime визначає `Signal`, `OpportunityCandidate`, `OpportunityRationale`, explainable Fit/Need/Timing/Access/Value scoring, lifecycle qualification та `OpportunityHandoff`.

Growth свідомо не володіє Sales Deal, Pipeline, Contract, Invoice або delivery state. V0.8 додає cross-domain Handoff Protocol: immutable Opportunity Package snapshot, `handoff_pending`, target registry, resumable running attempts, stable target-side idempotency per Candidate, accepted/rejected/failed outcomes та target reference. Growth не пише в persistence target Domain. V0.9 додає перший concrete target adapter для Sales: account-level package приймається лише коли latest Buying Committee має рівно одного explicit champion із email identity; тоді Sales через власний `SalesWriteService` створює inbound Lead і повертає `sales_lead` reference. V0.10 додає 34 tenant-safe Symfony API routes для Signal, Candidate, ICP, Account, Buying Committee, Research, Qualification і Handoff. Read operations вимагають `cos.tenant.access`; mutations — `cos.tenant.manage`, CSRF та `X-Idempotency-Key`. V0.11 додає provider-backed SSR Growth Workspace: overview, Opportunity queue, Account Intelligence, Candidate workspace та Account workspace. V0.12 розширює його Signal Operations: evidence stream `/growth/signals`, registry/run history `/growth/collectors`, collector counters, dedupe/partial/failure visibility та запуск collector через canonical API. V0.13 додає signed external Signal webhook `/webhooks/growth/signals`: HMAC-SHA256 по `timestamp.raw_body`, clock-skew guard, 1 MB body limit, explicit organization/source envelope, service actor та canonical Growth idempotency. Webhook не пише в persistence напряму, а викликає `GrowthApplicationBoundary::ingestExternalSignal()`. V0.14 додає governed Engagement Intelligence: evidence-bound Next Best Action vocabulary (`ignore`, `monitor`, LinkedIn, email, call, diagnostic, case study, introduction, webinar, report), sanitized Account/Buying Committee context, immutable model/context metadata, один active proposed recommendation на Candidate та explicit Accept/Dismiss. Recommendation не є executable Action і не відправляє повідомлення; конвертація в Kernel Action дозволяється лише наступним execution cycle там, де існують concrete handler + Policy. V0.15 закриває перший feedback loop із Sales через durable events: accepted Growth handoff reference (`sales_lead`) корелюється з Lead events; `LeadChanged.client_case_id.to` створює binding до `sales_deal`; contacted/qualified/disqualified/reply/meeting/won/lost нормалізуються в immutable Growth outcomes. `deal.won` переносить deal value + currency як economic outcome, але не називається recognized revenue. Growth не читає Sales persistence. Інші target adapters, provider-specific pull collectors, outbound execution та автоматичне optimization scoring/ICP належать наступним хвилям.

## Sales: продажі та попит

Sales володіє життєвим циклом попиту: зверненнями (Leads), справами клієнтів та угодами, воронками, активностями, наступними діями, автоматизацією продажів, правилами керування, операційними представленнями для читання та контрактами інтеграцій, потрібними продажам.

У canonical Domain layer уже виділені `Lead`, `Contact`, `Company`, `Opportunity`, `Pipeline` та `Activity`. Це не створює дубльованого persistence: чинний Deal/ClientCase runtime залишається compatibility path під час міграції.

## Diagnostic: діагностика бізнесу

Diagnostic володіє життєвим циклом методології, сесії, фактів, оцінювання та рекомендацій. У гілці `0.6.x` він має сервіс модуля середовища виконання, внесок маршрутів API, споживач подій і постійний діагностичний стан.

Sales-specific methodology та benchmark entrypoints живуть у Sales Diagnostics, а канонічне оцінювання й scoring залишаються в deterministic generic Diagnostic engine.

## Property: нерухомість

Property `0.12.0` є канонічним середовищем виконання для активів нерухомості.

```text
Property Asset — актив нерухомості
├─ ідентичність / походження / перевірка
├─ CREATE / MERGE / REVIEW — створення / об’єднання / перевірка ідентичності + аудит
├─ канонічні псевдоніми для сумісності зі старою моделлю
├─ структура / розташування / зв’язки
├─ Inventory — комерційний облік
├─ Listing / Publication — оголошення / публікація
├─ історія + події домену
├─ аналітика
├─ інтелект із прив’язкою до доказів
└─ зовнішня мережа Property Network + межа адаптера RESO
```

Основний шлях виконання:

```text
Web / API / Spatial
        ↓
канонічне середовище виконання Property
        ↓
Asset / Inventory / Listing / Publication
        ↓
шина подій / історія
        ↓
проєкція сумісності зі старою моделлю
```

Комерційний стан відділений від фізичного об’єкта нерухомості через Inventory. Публікація відділена від Inventory через моделі Listing і Publication.

`tn_properties` більше не є основною моделлю запису для стану Asset/Inventory/Listing. Шляхи зміни даних у вебінтерфейсі та публікація просторового туру входять через канонічне середовище виконання, а стара таблиця підтримується як перехідна проєкція й поверхня читання. Допоміжні механізми старої моделі для медіа, груп і читання поки залишаються там, де канонічна поверхня ще не потрібна.

## Каркасні домени V1

Finance, Procurement, HR і Construction залишаються installable skeleton Domains із канонічними моделями та Application contracts, але без повного runtime. Real Estate з Wave 9 та Service з Wave 11 уже мають активні runtime Domains і власні executable Process Registry definitions. Process-coverage exemptions для них видалено.

## Service: сервісні операції

Service `0.2.0` володіє життєвим циклом `Request → Ticket → Assignment/SLA → Escalation → Resolution → Close`. Assignment, SLA, Escalation і Resolution зберігаються як історичні записи; concurrent Ticket mutations серіалізуються row lock-ом; Request і ServiceCase закриваються автоматично після закриття останніх Tickets.

## Платформні документи

Documents з Wave 10 є активною Platform capability. Вона надає tenant-safe runtime для Upload, Attach, Version, template generation, signature lifecycle та Archive, із Symfony CQRS/API, Platform storage, MySQL metadata, idempotency, Event і Audit.

Documents навмисно не є installable Domain: Sales, Property, HR, Finance та інші бізнес-домени використовують `DocumentAttachmentPort` і не володіють document persistence.

## Допоміжні предметні області

Identity, Content і Spatial існують як окремі допоміжні області відповідальності, але їхня зрілість як модулів середовища виконання не дорівнює встановлюваним бізнес-доменам вище.

## Правило правдивості

```text
поточний код / тести / декларації main
        ↓
згенерований технічний довідник
        ↓
пояснювальна сторінка поточного стану
```

Цільовий стан (TARGET) і дорожня карта не описуються як уже реалізована поведінка.
