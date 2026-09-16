---
title: Додавання Domain
description: Практична інструкція створення нового bounded context у COS.
status: active
updated: 2026-09-16
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

## Manifest `module.php`

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
