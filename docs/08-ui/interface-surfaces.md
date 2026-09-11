---
title: Interface Surfaces
description: Web, API, Telegram і CLI як delivery layer COS.
status: active
updated: 2026-09-11
kind: ui
---

# Interface Surfaces

`app/Interfaces` — delivery layer COS.

## Surfaces

- `Web` — HTML/UI delivery;
- `Api` — programmatic HTTP delivery;
- `Telegram` — messenger delivery;
- `Cli` — workers/commands/operations entrypoints;
- `Shared` — delivery-level shared utilities.

## Web

Поточний Web interface окремо має `Controller`, `Routing`, `Navigation`, `Page`, `Rendering`, `View`, `Assets`, `Security`, `Tenant`, `Service` та `Module.php`.

Це важливе відокремлення від старої моделі, де великий frontend module ставав фактичним application core.

## Controller rule

Controller має:

```text
parse request
→ auth/tenant/capability check
→ build DTO/command
→ call application service/use case
→ map result to response/view
```

Controller не має:

- domain transition logic;
- SQL;
- policy catalog;
- provider routing;
- LLM prompt/business interpretation.

## Tenant

Delivery layer встановлює active organization/tenant context, але domain operations усе одно мають явно бути tenant-safe. Session сама по собі не є security boundary.

## Navigation і modules

UI navigation може залежати від active modules/capabilities. Вимкнений domain module не повинен залишати «мертві» меню та routes.

## Read models

Складні workspace/dashboard screens мають читати projections/read models. Не потрібно змушувати domain write repository одночасно бути BI-запитом на 14 JOIN-ів.

## Shared behavior across channels

Web, API, Telegram та CLI повинні викликати однакові application boundaries. Якщо Telegram має іншу business rule, ніж Web, це майже завжди defect architecture, а не «особливість каналу».