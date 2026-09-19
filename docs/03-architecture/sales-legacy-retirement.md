---
title: Видалення legacy Sales API
description: "Фінальний retirement legacy Sales transport і SSR: Symfony володіє /api/v1/sales/* та всіма /sales/* HTML surfaces; Phalcon Sales transport видалений."
status: active
updated: 2026-09-19
kind: architecture
contract: architecture-v1
---

# Видалення legacy Sales API

Після Wave 7 live Sales frontend більше не залежить від Phalcon API. Legacy deletion закриває transport-рівень, який після cutover став недосяжним production-кодом.

## Видалено

- усі Phalcon routes під `/api/sales/*`;
- legacy CRM webhook compatibility routes під `/api/integrations/*`;
- Phalcon Sales API controllers;
- Phalcon CRM webhook controller;
- окремий `SalesDirectorRoutes`;
- transport-specific tests, які вимагали існування старого API.

## Залишено навмисно

Phalcon більше не рендерить `/sales/*`. Canonical HTML owner — Symfony PHTML renderer поверх тих самих Application/read-model contracts.

Канонічний інтерактивний шлях:

```text
/sales/* HTML shell
      ↓
frontend/features/sales/*
      ↓
/api/v1/sales/*
      ↓
Symfony HTTP
      ↓
CommandBus / QueryBus
      ↓
Sales Application / Domain
      ↓
canonical ports / MySQL adapters
```

MySQL adapters у `app/Domains/Sales/Infrastructure` не видаляються лише через те, що вони MySQL. Вони залишаються канонічною Infrastructure реалізацією доти, доки Application ports реально їх використовують.

## Вхідні CRM-повідомлення

Канонічний webhook:

```text
POST /api/v1/integrations/crm/{id}/webhook
```

Старі provider/organization та Phalcon integration webhook paths більше не є compatibility surface.

## Незворотний architecture gate

`tests/architecture/sales_legacy_api_retirement.php` забороняє:

- відновлення legacy Sales API controllers;
- повернення `/api/sales/*` або `/sales/*` у Phalcon routing;
- повернення старих CRM webhook routes;
- повернення legacy API dependency у Sales frontend.

Одночасно gate перевіряє наявність канонічних Symfony Sales/API routes.

## Наступний deletion slice

Після transport retirement можна окремо видаляти тільки ті compatibility services, CLI entrypoints і adapters, для яких caller audit показує нуль production consumers.

Це не є дозволом масово видаляти Sales MySQL repositories: persistence adapter є legacy лише тоді, коли його замінив канонічний runtime і він більше не стоїть за активним Application port.

`app/Interfaces/Web/Controller/SalesController.php` та legacy Sales Web route contributors видалені; architecture gates забороняють їх відновлення.
