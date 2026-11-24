---
title: Додавання Domain
description: Практична інструкція створення нового bounded context у COS.
status: active
updated: 2026-11-24
kind: how-to
---

# Додавання Domain

Новий Domain (домен) створюється не тому, що з’явилась нова таблиця, сторінка або набір класів. Він виправданий, коли система отримує окрему бізнесову область із власною мовою, правилами та відповідальністю.

## Коли потрібен окремий Domain

Має бути більшість таких ознак:

- власний бізнесовий словник;
- власні invariants (інваріанти);
- окремий стан і lifecycle (життєвий цикл);
- змістовні Use Cases (сценарії використання);
- власність на дані;
- власні Domain Events;
- окремі Policy або Automation;
- потреба в незалежній активації чи конфігурації.

## Базова структура

```text
app/Domains/<Name>/
├── Model
├── Application
│   ├── Contract
│   ├── DTO
│   └── UseCase
├── Automation       # лише за наявності відповідальності за автоматизацію
├── Infrastructure  # adapters, якими володіє Domain
├── Bootstrap        # якщо Domain робить runtime contributions
└── module.php       # якщо Domain є installable module
```

Не створюйте порожні директорії «на майбутнє». Архітектура не стає кращою від кількості папок.

## Послідовність

1. Визначте ownership: чим Domain володіє і чим **не** володіє.
2. Опишіть vocabulary, value objects та enums.
3. Виділіть прикладні Use Cases.
4. Визначте мінімальні outbound ports у `Application/Contract`.
5. Реалізуйте Domain-owned adapters в `Infrastructure`.
6. Для змін стану, важливих іншим процесам, створіть Domain Event.
7. За потреби автоматизації додайте Rules, Agent, Actions і Policies.
8. Якщо Domain має бути підключуваним, додайте реалізацію `DomainModuleInterface` та `module.php`.
9. Для installable Domain додайте щонайменше один канонічний workflow у Process Registry або явний виняток покриття.
10. Зареєструйте concrete dependencies у `app/Bootstrap`, а не у Web module.
11. Додайте architecture, smoke та integration tests і пройдіть documentation gates.

## Маніфест `module.php`

Manifest визначає:

- `id`;
- version і schema version;
- сумісність із Kernel;
- dependencies;
- default activation;
- runtime contributions;
- capabilities.

Contributions можуть включати runtime module service, job handlers, API route contributors, configuration provisioners, migrations та capabilities.

Наявність `module.php` робить Domain installable для документаційних перевірок. Директорія без manifest залишається supporting area і не отримує ці вимоги автоматично.

## Покриття бізнес-процесом

Installable Domain не є повністю описаним лише тому, що його класи компілюються.

```text
module.php
   ↓
installable Domain
   ↓
Process Registry ownership
   ↓
Workflow + capability/runtime evidence
```

Нормальний шлях: створити workflow за інструкцією [Додавання Workflow](adding-a-workflow.md).

Якщо Domain свідомо не має process model, виняток реєструється в:

```text
docs/.vitepress/process-coverage-exemptions.json
```

Виняток повинен містити `domain`, відповідального `owner` і змістовний `reason`. Коли process з’являється, застарілий exemption має бути видалений.

Поточне покриття: [Domain Process Coverage](../12-reference/domain-process-coverage.md).

## Версіонування

Сумісність Kernel перевіряється через executable `KernelVersion` і `VersionConstraint`. Назва коміту не є версією контракту.

## Напрям залежностей

```text
Domain → Kernel contracts + same Domain
Domain -X-> concrete Infrastructure
Domain -X-> Web/Telegram controllers
Domain -X-> PDO/Phalcon APIs у core logic
```

## Cross-domain взаємодія

Domain A не читає таблиці Domain B напряму. Використовуйте явний application contract, integration event, read projection або іншу declared boundary.

`domain` у Process Registry також не може посилатися на неіснуючий installable Domain.

## Готовність

Domain готовий, коли:

- boundary зрозуміла;
- tenant scope явний;
- writes атомарні з Events там, де це потрібно;
- зовнішні side effects ідемпотентні;
- capability та access rules визначені;
- installable Domain має process coverage або формальний exemption;
- architecture/documentation tests не допускають випадкових залежностей.

## Паспорт Domain (DAS-001)

Для кожного installable Domain обов'язковий `docs/04-domains/<id>/passport.md` із 14 секціями та машинним блоком `COS-DOMAIN-PASSPORT-DATA`. Шаблон: `docs/.vitepress/templates/domain-passport.md`. Документ містить ownership і пояснення, а фактичні IDs/версії/контракти отримує з `module.php`, Process Registry та generated reference. Internal components не є самостійними installable modules.

Перед merge виконайте `node docs/.vitepress/check-domain-passports.mjs` та `npm run docs:check`. Паспорт не може стверджувати D3/D4/D5 без independently linked acceptance evidence. Дивіться `docs/03-architecture/cos-domain-architecture-standard.md`.

## Обов'язковий порядок DAS-001 для нового функціоналу

1. Визначити Domain-owned use case і компонент паспорта; не створювати окремий executor.
2. Додати реальний handler, типізований контракт у manifest, input/output schemas та permission. Нові descriptive-only IDs не допускаються.
3. Query і human-only low-risk command підключити до canonical Capability Gateway; external, financial та irreversible mutations — тільки через Platform Workflow/Action з approval та idempotency. Read observations мають окрему canonical runtime authority.
4. Додати позитивні, negative authorization, cross-tenant та replay/recovery tests відповідно до виду операції; для mutation потрібен native readback.
5. Оновити компонент паспорта, Process Registry та declared cross-domain contracts у тому самому PR. Новий процесний крок не може посилатися на legacy descriptive ID.
6. Для нового installable Domain внести запис baseline з нульовим descriptive/process debt і фактичним typed floor, паспорт та процес або обґрунтований exemption.
7. Виконати `npm run docs:generate`, `npm run docs:generate:check`, `npm run docs:check`, `bash bin/verify fast` та відповідні runtime/integration suites. PR відкривається від актуального main; readiness не підвищується без acceptance evidence.

`docs/.vitepress/das-001-legacy-surface.json` фіксує точні старі descriptive IDs і process references, а не дозвіл їх викликати. Нормалізований ID або посилання вилучається з цього реєстру в тому самому PR; descriptive ceiling зменшується. Не видаляти чинні access/UI identifiers без аудиту споживачів. Для суворого контролю завершення боргу виконується `npm run docs:domain-zero-debt`; команда навмисно повертає ненульовий код, поки є legacy surface або process gaps.

У pull request CI порівнює реєстри з точним SHA базової гілки: додавання або підміна legacy ID, descriptive process reference чи process gap блокуються навіть при одночасному редагуванні manifest і реєстру. Допускається погашення боргу, а не його перенесення на інший ID.

### Diagnostic Methodology Studio: транзакційна межа

Studio mutation commands використовують спільний tenant-scoped lock та транзакцію repository; validation, regression evidence, native compiled revision, publication status, activation і audit комітяться разом. Існуюча published native revision не перезаписується, active version не архівується до перемикання. Повторна публікація не виконує повторної активації. Це hardening наявної межі, а не новий публічний Capability Contract; окреме runtime acceptance залишається обов'язковим.
