---
title: Supporting Domains and Extracted Areas
description: Поточний статус Identity, Content і Spatial у COS architecture.
status: active
updated: 2026-09-11
kind: domain
---

# Supporting Domains and Extracted Areas

Не всі bounded areas у COS мають однакову зрілість. Ця сторінка навмисно це показує.

## Identity

`app/Domains/Identity` сьогодні має `Application` та `Infrastructure`.

Це правильний напрямок: identity-specific operations відділяються від Web/session/framework implementation.

Але поки немає повного domain module manifest/runtime contribution на рівні Sales. Не треба документувати відсутні aggregates/events/capabilities так, ніби вони вже існують.

## Content

`app/Domains/Content` має `Application` та `Infrastructure`.

Сенс extraction: content workflow має викликатися з Web/API/automation як application capability, а не бути business logic усередині controller або n8n webhook.

## Spatial

`app/Domains/Spatial` має `Application` та `Infrastructure`; shared technical implementation також присутня під `app/Infrastructure/Spatial`.

Boundary має залишатися такою:

```text
Interface
→ Spatial application contract/use case
→ Spatial/domain-owned adapter або shared Spatial infrastructure
```

а не:

```text
Controller → SQL/convert command/provider directly
```

## Мета для цих areas

Коли з'являються власні state machine, business events, policies, installable configuration або tenant-level activation, area може дорости до повного `DomainModuleInterface` + `module.php` contract.

Не потрібно створювати церемоніальні `Automation`, `Model`, `Policy` папки наперед. Папка з нульовою поведінкою архітектури не додає, зате IDE почувається дуже зайнятою.