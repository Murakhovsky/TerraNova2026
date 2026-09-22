---
title: Карта доменів COS
description: Карта встановлюваних доменів, допоміжних областей, ядра та меж інтерфейсів і інфраструктури.
status: active
updated: 2026-09-22
kind: architecture
---

# Карта доменів COS

Ця карта описує **фактичний стан (AS-IS) поточного `main`**. Код, тести, декларації та документація читаються з одного канонічного коміту.

## Карта системи

```text
                         Kernel 0.11.9
       події / правила / агенти / дії / політики / черги / аудит
                                  │
                    контракти середовища виконання
                                  ▼
          ┌───────────────────────┼───────────────────────┐
          │                       │                       │
        Sales                 Diagnostic              Property
       0.8.6                    0.6.1                  0.12.0
  еталонний модуль        діагностичний модуль    модуль нерухомості
          │                       │                       │
          └───────────────────────┼───────────────────────┘
                                  │ порти / контракти
                                  ▼
                           Infrastructure
                 MySQL / CRM / LLM / Media / інші адаптери

Допоміжні області: Identity / Content / Spatial
Інтерфейси: Web / API / Telegram / CLI
Bootstrap: корінь композиції
```

## Домен, директорія і модуль не тотожні

У COS потрібно розрізняти:

```text
директорія домену
≠
встановлюваний модуль
≠
повністю інтегрований домен середовища виконання
```

Installable Domains мають `module.php` та входять до згенерованого довідника модулів. Growth `0.5.0` має installable contract, persistence/application runtime, ICP/Account/Buying Committee Intelligence та provider-agnostic Signal Collector runtime, але лишається вимкненим за замовчуванням, доки delivery та cross-domain acceptance surfaces не пройдуть окремий cutover.

Identity, Content і Spatial фізично відокремлені як обмежені області відповідальності (bounded areas), але не зобов’язані мати той самий контракт встановлюваного модуля.

## Ядро

Ядро (Kernel) володіє **механізмами**, а не бізнес-семантикою. Воно може знати про подію (Event), правило (Rule), агента (Agent), дію (Action), політику (Policy), погодження (Approval), чергу (Queue), аудит (Audit), організаційний контекст (Tenant), модуль (Module), мовну модель (LLM) та спостережуваність (Observability).

Ядро не повинно знати, що таке кваліфіковане звернення, діагностична знахідка або модерація об’єкта нерухомості.

## Growth: пошук бізнес-можливостей

Growth `0.5.0` володіє Signal Intake, ICP, Account Intelligence, Contact/Buying Committee Intelligence та раннім lifecycle бізнес-можливості до explicit handoff:

```text
ICP
→ Account
→ Account Evidence
→ Contact / Buying Committee Evidence
→ Signal
→ OpportunityCandidate
→ Research / Rationale
→ Explainable Score
→ Qualification
→ OpportunityHandoff
```

Канонічний інваріант: **Growth не створює Lead або Deal**. Він знаходить, досліджує, оцінює, пріоритизує та маршрутизує можливість. Sales або інший target Domain створює власний execution aggregate тільки після прийнятого handoff.

`Signal` зберігає observable facts. `OpportunityRationale` зберігає interpretation, WHY IT MATTERS, problem hypothesis, WHY NOW, evidence, counter-evidence, assumptions та unknowns.

Людина в Growth має стабільну `GrowthContact` identity з provenance. Посада, department, seniority, buying role і relationship strength не вважаються вічними властивостями людини: вони зберігаються як immutable `ContactSnapshot` у контексті конкретного Account.

`BuyingCommitteeAssessment` детерміновано рахує required-role coverage, gaps, champions, blockers і relationship risk із конкретних snapshot ids та фіксує `model_version`.

Поточний canonical process `growth.opportunity-candidate-to-handoff` має стан `to-be`. Domain model, MySQL runtime, ICP/Account/Contact intelligence, provider-agnostic collector registry, source dedupe, collector run accounting, idempotency, Events і Audit уже визначені; concrete provider adapters, engagement, cross-domain acceptance, agents, API та UI ще не оголошуються реалізованими.

## Sales: продажі

Sales володіє операційним життєвим циклом попиту:

```text
Lead — звернення
→ Client Case / Deal — справа клієнта / угода
→ Pipeline — воронка
→ Activities / Follow-up — активності / наступні дії
→ Outcome — результат
```

Sales також володіє власними подіями, правилами, агентами, діями, політиками, перекладом до CRM, робочими представленнями та повноваженнями продажів.

Sales залишається еталонним прикладом повного модульного шаблону COS.

## Diagnostic: діагностика

Diagnostic володіє життєвим циклом діагностики, заснованої на фактах:

```text
Methodology — методологія
→ Session — сесія
→ Evidence — докази / вихідні дані
→ Facts / Metrics — факти / показники
→ Evaluation — оцінювання
→ Findings / Hypotheses — знахідки / гіпотези
→ Recommendations — рекомендації
```

ШІ в Diagnostic може допомагати з витягуванням та інтерпретацією інформації, але детерміноване оцінювання не повинно перетворювати відповідь мовної моделі на недоторканну істину.

## Property: нерухомість

Property `0.12.0` володіє канонічною моделлю нерухомості та її комерційного використання. Основні частини:

- актив нерухомості та його ідентичність;
- структура й розташування;
- Inventory, тобто комерційний облік;
- Listing і Publication, тобто оголошення та публікація;
- історія й події домену;
- аналітика та інтелект із прив’язкою до доказів;
- зовнішня мережа Property Network і межі адаптерів.

Фізичний об’єкт не стає «проданим» як сутність. Комерційний стан змінюється в Inventory. Це один із ключових інваріантів поточної моделі.

## Допоміжні області

### Identity

Область ідентичності та пов’язаних прикладних і інфраструктурних механізмів.

### Content

Процеси контенту та межа інтеграцій із ним.

### Spatial

Просторові та 3D-сценарії застосунку й інфраструктури.

Їх не слід автоматично прирівнювати до встановлюваних доменів лише тому, що у файловій системі вже є красиві директорії. Файлова система, на щастя, ще не отримала право проєктувати архітектуру.

## Інтерфейси

`Web`, `API`, `Telegram`, `CLI` є адаптерами доставки взаємодії. Вони можуть приймати вхідні дані, встановлювати контекст автентифікації та організації, перетворювати транспортні DTO, викликати сервіс застосунку або середовища виконання та повертати відповідь.

Вони не визначають володіння домену та бізнес-переходи.

## Інфраструктура

Інфраструктура реалізує технічні адаптери й порти, наприклад:

```text
Persistence      збереження даних
Provider clients клієнти зовнішніх постачальників
LLM transport    транспорт до мовних моделей
Media            медіа
Security         безпека
Observability    спостережуваність
Integration      інтеграційні механізми
```

## Bootstrap

`app/Bootstrap` є коренем композиції (composition root). Саме тут конкретні реалізації збираються в робочий застосунок.

## Напрям залежностей

```text
Kernel         → універсальні контракти PHP і платформи
Domain         → Kernel + той самий Domain
Infrastructure → контракти Domain / Kernel
Interfaces     → відкриті сервіси застосунку / середовища виконання
Bootstrap      → конкретне складання всіх шарів
```

## Машинна карта

Точні версії, можливості, міграції та внески розширень не дублюються вручну. Для цього використовуйте:

- [довідник модулів і можливостей](../12-reference/module-capabilities.md);
- [точки розширення модулів](../12-reference/extension-points.md);
- [варіанти використання застосунку](../12-reference/application-use-cases.md);
- [типи подій](../12-reference/event-types.md);
- [довідник команд DTO](../12-reference/commands.md).

## Критерій нового домену

Окремий домен виправданий, коли з’являються власні словник понять, інваріанти, життєвий цикл і стани, варіанти використання, володіння даними, подіями та межами повноважень.

Telegram, транспорт електронної пошти, файлове сховище або телеметрія самі по собі доменів не утворюють.
