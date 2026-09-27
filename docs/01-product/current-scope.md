---
title: Поточний стан COS
description: Фактичний продуктовий та архітектурний обсяг поточного main.
status: active
updated: 2026-09-26
kind: product
---

# Поточний стан COS

Ця сторінка описує **фактично реалізований стан (AS-IS)** поточного `main`, а не дорожню карту або бажаний майбутній стан.

## Базовий виконуваний стан

| Компонент | Версія | Поточний стан |
| --- | --- | --- |
| Kernel | `0.11.9` | виконуваний контракт платформи |
| Sales | `0.8.6` | повний модуль середовища виконання та еталонний домен |
| Growth | `0.50.0` | Growth Operating System: Market Discovery → Opportunity Intelligence → governed Engagement → Reply/Routing → Sales/Service feedback → Learning; schema `0.50.0`; disabled by default до production cutover |
| Diagnostic | `0.6.1` | встановлюваний модуль із маршрутами API, споживачем подій і постійним станом |
| Property | `0.12.0` | встановлюваний модуль із канонічними записами Asset/Inventory/Listing, сумісним представленням, аналітикою, інтелектом і зовнішньою взаємодією |
| Finance | `0.1.0` | встановлюваний V1 skeleton; runtime і persistence навмисно відкладені |
| Procurement | `0.1.0` | встановлюваний V1 skeleton; runtime і persistence навмисно відкладені |
| Service | `0.2.0` | активний runtime Request → Ticket → Assignment/SLA → Escalation → Resolution → Close; Symfony API, persistence, events, audit та idempotency |
| Construction | `0.1.0` | встановлюваний V1 skeleton; runtime і persistence навмисно відкладені |
| Hr | `0.1.0` | встановлюваний V1 skeleton; runtime і persistence навмисно відкладені |
| Real_estate | `0.2.0` | активний brokerage runtime поверх Property: Opportunity → Property Match → Offer → Viewing → Reservation; Symfony API, persistence, events, audit та idempotency |

Машиночитані факти: [довідник модулів і можливостей](../12-reference/module-capabilities.md).

## Growth: інтелект можливостей

Growth `0.50.0` є окремим bounded context для **FIND VALUE** і вже охоплює повний цикл від пошуку ринку до навчання за фактичним результатом.

Канонічний ланцюг:

```text
Market Universe
    ↓
Account Discovery / Enrichment / ICP Fit
    ↓
Signal
    ↓
OpportunityCandidate
    ↓
Research / WHY NOW / Qualification
    ↓
Buying Committee
    ↓
Engagement / Next Best Action
    ↓
Governed Outreach
    ↓
Inbound Reply / Classification
    ↓
Deterministic Conversation Routing
    ↓
Sales / Service
    ↓
Outcome Feedback
    ↓
Growth Learning / Experiments / Optimization
```

Ключові інваріанти: **Signal != Opportunity**; AI дає пропозиції та класифікацію, але не отримує прихованої mutation authority; усі записи tenant-scoped; mutation paths мають idempotency; raw credentials та contact identity values не потрапляють у LLM context або операторські проєкції; Growth не пише напряму в persistence Sales чи Service.

V0.35–V0.47 додали pre-handoff LinkedIn/call/email execution, delivery feedback, tenant limits, channel quotas, atomic capacity admission, activation policy, controlled autonomous outreach, governed content drafting/review, outreach sequences, inbound replies, AI classification, deterministic Conversation Routing та email delivery parity. V0.48 додав Market Universe та automated Account sourcing через credentialed HTTPS JSON provider. V0.49 закрив COS-for-COS golden path `Market → Account → Signal → WHY NOW → Opportunity → Committee → Outreach → Reply → Route → Sales → Outcome → Learning`. V0.50 додав resumable Market Discovery, run leases, partial retry semantics, rejected-row accounting, cursor safety та повний release-hardening gate.

Модуль усе ще має `enabled_by_default=false`. Це свідомий production gate: перед V1 потрібні production cutover, smoke/rollback процедура та підтвердження повного інтеграційного CI.

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
