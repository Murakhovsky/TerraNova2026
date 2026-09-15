---
title: Adding a Workflow
description: How to document and implement a business workflow across Domain, Runtime, UI and code boundaries.
status: active
updated: 2026-09-15
kind: how-to
contract: how-to-v1
---

# Adding a Workflow

Workflow починається з business goal, а не з нового Service class.

## 1. Define business contract

Зафіксуйте:

- business goal;
- actors;
- trigger/input;
- expected output/outcome;
- decision points;
- failure paths.

Одразу визначте `process_state`:

- `as-is` — поточний реальний процес;
- `to-be` — цільовий процес;
- `runtime-verified` — critical transitions мають executable mapping у current `main`.

## 2. Draw the business flow

Канонічний workflow має Mermaid diagram. Спочатку показуйте business sequence, а не class graph.

```mermaid
flowchart TD
    A[Trigger] --> B[Use Case / Command]
    B --> C{Domain decision}
    C -->|Allowed| D[State change + Event]
    C -->|Denied| E[Explicit failure]
    D --> F[Business outcome]
```

Для actor/system interaction додавайте `sequenceDiagram`, для lifecycle однієї entity — `stateDiagram-v2`. Не змішуйте всі три питання в одну схему тільки тому, що Mermaid це дозволяє.

Повні conventions: [Business Process Modeling](../02-workflows/business-process-modeling.md).

## 3. Assign ownership

Для кожного state change визначте Domain owner. Cross-domain workflow може координувати кілька Domains, але не створює shared table, яким усі тихо володіють одночасно.

## 4. Map execution

Narrative та Mermaid flow зв'яжіть із executable boundaries:

```text
Trigger
  ↓
Use Case / Command
  ↓
Domain validation
  ↓
State change + Event
  ↓
Rule / Agent (optional)
  ↓
Policy / Approval
  ↓
External action (optional)
  ↓
Result / Audit
```

Не кожен step потребує окремого class. Документуйте meaningful business sequence, а exact executable inventory лишайте generated reference.

Для `runtime-verified` critical transitions повинні мати зрозумілий mapping на current use cases, commands, events, policies або generated reference.

## 5. Connect UI and code

Workflow page повинна вказати UI surfaces та Code map, щоб одна сторінка зв'язувала бізнес, UX, Runtime і implementation.

UI click не є business transition сам по собі. Diagram має називати business action/result, якщо UI лише доставляє intent.

## 6. Verify

Перевірте:

- happy path;
- forbidden transitions;
- duplicate delivery/idempotency;
- external failure/retry;
- tenant scope;
- auditability;
- відповідність Mermaid diagram narrative тексту;
- відповідність `process_state` фактичній зрілості process.

Запустіть:

```bash
npm run docs:check
npm run docs:build
```

`workflow-v2` не пройде check без `process_state` і Mermaid diagram.

## Canonical examples

- [Sales Lead → Managed Case](../02-workflows/sales-lead-to-managed-case.md)
- [Property Submission → Managed Property](../02-workflows/property-submission-to-publication.md)
- [Diagnostic Session → Recommendation](../02-workflows/diagnostic-session-to-recommendation.md)
