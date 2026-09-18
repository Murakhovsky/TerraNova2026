---
title: Перенесення Sales Frontend на Symfony
description: "Сьома хвиля другої фази міграції COS: усі інтерактивні Sales API-виклики переходять з legacy /api/sales на Symfony /api/v1/sales."
status: active
updated: 2026-09-18
kind: architecture
contract: architecture-v1
---

# Перенесення Sales Frontend на Symfony

Wave 7 робить Symfony єдиним API boundary для інтерактивного Sales UI.

На цьому етапі Phalcon ще може рендерити server-side HTML сторінок `/sales/*`, але JavaScript, форми та live controls більше не повинні звертатися до legacy `/api/sales/*`.

Це навмисний strangler-крок перед Wave 8, де legacy Sales API та зайві controller routes можна буде прибирати без ризику зламати живий UI.

## Канонічний шлях

```text
Legacy-rendered Sales page
        ↓
frontend/features/sales/*
        ↓
/api/v1/sales/*
        ↓
Symfony Controller
        ↓
CommandBus / QueryBus
        ↓
Sales Application / Kernel
        ↓
existing ports and MySQL adapters
```

HTML renderer не визначає business ownership. Після Wave 7 інтерактивний runtime ownership належить Symfony.

## Core Sales Workspace

На Symfony V1 переведені:

- глобальний Sales search;
- Deal intelligence;
- stage change;
- quick update;
- owner assignment;
- activity creation;
- activity completion та reschedule;
- next action / follow-up;
- meeting;
- outbound communication;
- Lead update;
- Lead → Opportunity;
- Lead follow-up;
- approval approve/reject;
- Action execute/dismiss.

Frontend використовує нормальні HTTP semantics: `GET`, `POST`, `PATCH`, `PUT`, а не універсальний legacy POST.

## Sales Administration

Symfony frontend administration boundary використовує існуючі application contracts, а не дублює конфігураційну логіку.

Перенесені surfaces:

- pipelines, stages, transitions, lost reasons, clone та revisions;
- rules і lifecycle;
- agents та read-only test;
- policies та preview;
- teams, memberships і capabilities;
- integrations використовують Wave 6 control plane.

## Авторизація

Core workspace зберігає manager boundary.

Administration використовує granular Sales capabilities через `SalesAccessControlInterface`:

- `sales.admin.pipeline.manage`;
- `sales.admin.rules.manage`;
- `sales.admin.agents.manage`;
- `sales.admin.policies.manage`;
- `sales.admin.teams.manage`.

Integration administration продовжує використовувати Wave 6 tenant management boundary.

Approval decisions використовують `MysqlSalesApprovalAuthority`, тому team/capability rules не обходяться під час переходу на Symfony.

## Безпека write-потоків

Symfony mutations:

- беруть organization тільки з authenticated TenantContext;
- перевіряють активний Sales module;
- перевіряють CSRF;
- передають correlation id у application layer;
- використовують наявні optimistic configuration versions для admin configuration;
- для Wave 2 / Wave 6 сценаріїв зберігають існуючу semantic idempotency модель;
- Action execution проходить через Symfony Messenger;
- credentials integrations не повертаються у frontend.

## Frontend guard

CI рекурсивно перевіряє Sales JavaScript та Sales views.

Заборонений runtime dependency:

```text
/api/sales/*
```

Дозволений інтерактивний boundary:

```text
/api/v1/sales/*
```

Це правило потрібне для Wave 8: legacy Sales API можна видаляти лише коли frontend guard залишається зеленим.

## Межа з Wave 8

Після Wave 7 ще можуть існувати:

- Phalcon page controllers;
- legacy Sales API controllers;
- legacy routing declarations;
- compatibility services, потрібні server-rendered сторінкам.

Але live frontend не повинен від них залежати.

Wave 8 має:

1. перенести або спростити page rendering boundary;
2. перевірити, які legacy Sales controllers більше не мають callers;
3. видалити legacy `/api/sales/*` routes;
4. прибрати dead compatibility wiring;
5. залишити MySQL adapters тільки там, де вони ще є canonical infrastructure ports.

## Критерії завершення Wave 7

Wave 7 завершений, коли:

1. `workspace.js`, `admin.js`, `rule-editor.js` не містять legacy Sales API calls;
2. Sales views не генерують legacy Sales API URLs;
3. core workspace працює через Symfony V1;
4. admin surfaces працюють через Symfony V1;
5. granular Sales capabilities збережені;
6. approval authority не послаблена;
7. HTTP controllers не містять PDO або прямих `Domains\\...` dependencies;
8. Symfony container та router проходять validation;
9. frontend JS проходить syntax checks;
10. Wave 7 architecture guard і runtime E2E проходять у CI.
