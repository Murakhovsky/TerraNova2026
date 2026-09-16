---
title: Допоміжні домени та виділені області
description: Поточний статус Identity, Content і Spatial у архітектурі COS.
status: active
updated: 2026-09-16
kind: domain
---

# Допоміжні домени та виділені області

Не всі bounded areas у COS мають однакову зрілість і не кожна директорія під `app/Domains` автоматично є installable Domain module.

Канонічна різниця:

```text
bounded area / extracted capability
        ≠
installable Domain with module.php + runtime contributions
```

## Identity

`app/Domains/Identity` має Application та Infrastructure boundaries.

Напрямок правильний: identity-specific operations відділяються від Web/session/framework implementation.

Станом на поточний `main` Identity не має повного installable `module.php` contract на рівні Sales/Property/Diagnostic. Тому документація не повинна вигадувати для нього відсутні module capabilities, Process coverage або runtime contributions.

Identity стає повним installable Domain лише тоді, коли система справді потребує його незалежного module lifecycle, а не для симетрії дерева директорій.

## Content

`app/Domains/Content` має Application та Infrastructure boundaries.

Його призначення: content workflows мають викликатися з Web/API/automation через application capability, а не жити як business logic усередині controller, webhook або template.

Content поки не потребує удаваного module lifecycle лише тому, що слово «Content Domain» добре виглядає на діаграмі.

## Spatial

`app/Domains/Spatial` має Application та Infrastructure boundaries; shared technical implementation також існує під `app/Infrastructure/Spatial`.

Канонічна межа:

```text
Interface
→ Spatial application contract/use case
→ Spatial-owned adapter або shared Spatial infrastructure
```

а не:

```text
Controller → SQL / convert command / provider directly
```

Property `0.12.0` уже декларує explicit cross-domain contract:

```text
Domains\Spatial\Application\Contract\PropertyTourPublisherInterface
```

Property має роль `provides`, counterpart `spatial`, kind `integration_adapter`. Це доводить реальну межу інтеграції, але саме по собі ще не робить Spatial installable module.

## Коли area стає installable Domain

Сигнали для переходу:

- власний стійкий business vocabulary;
- власний state/lifecycle;
- meaningful use cases та invariants;
- business Events;
- policies/automation;
- tenant-level activation/configuration;
- власні migrations/capabilities;
- потреба незалежного runtime/module lifecycle.

Тоді area може отримати:

```text
DomainModuleInterface implementation
+ module.php
+ capabilities
+ runtime contributions
+ migrations/configuration where needed
+ canonical Process coverage або explicit exemption
```

## Правило

Не створюйте церемоніальні `Model`, `Automation`, `Policy`, `module.php` та порожні capabilities наперед.

Архітектура має фіксувати реальне ownership і поведінку. Папка з нульовою семантикою додає переважно впевненість файловому менеджеру.

## Пов’язані матеріали

- [Карта доменів](../03-architecture/domain-map.md)
- [Міждоменні контракти](../03-architecture/cross-domain-contracts.md)
- [Додавання Domain](../09-development/adding-a-domain.md)
- [Domain Process Coverage](../12-reference/domain-process-coverage.md)
