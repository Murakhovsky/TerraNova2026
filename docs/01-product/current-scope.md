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
| Growth | `0.4.0` | Opportunity + ICP/Account + Buying Committee Intelligence; evidence-backed contacts, role coverage/gaps, relationship risk; disabled by default |
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

Growth `0.4.0` розвиває окремий bounded context для **FIND VALUE**. Canonical runtime визначає `Signal`, `OpportunityCandidate`, `OpportunityRationale`, explainable Fit/Need/Timing/Access/Value scoring, lifecycle qualification та `OpportunityHandoff`.

Growth свідомо не володіє Sales Deal, Pipeline, Contract, Invoice або delivery state. V0.4 поверх versioned ICP та Account Intelligence додає Growth Contact identity з provenance, immutable ContactSnapshot для account-specific ролі, Buying Roles, relationship strength з evidence, deterministic Buying Committee Assessment та Committee Brief. Модуль залишається вимкненим за замовчуванням; external collectors, AI agents, engagement/outreach, cross-domain acceptance, API та production UI належать наступним хвилям.

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
