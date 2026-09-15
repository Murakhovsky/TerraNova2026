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

## 2. Assign ownership

Для кожного state change визначте Domain owner. Cross-domain workflow може координувати кілька Domains, але не створює shared table, яким усі тихо володіють одночасно.

## 3. Map execution

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

## 4. Connect UI and code

Workflow page повинна вказати UI surfaces та Code map, щоб одна сторінка зв'язувала бізнес, UX, Runtime і implementation.

## 5. Verify

Перевірте happy path, forbidden transitions, duplicate delivery/idempotency, external failure/retry, tenant scope та auditability.

## Canonical examples

- [Sales Lead → Managed Case](../02-workflows/sales-lead-to-managed-case.md)
- [Property Submission → Publication](../02-workflows/property-submission-to-publication.md)
- [Diagnostic Session → Recommendation](../02-workflows/diagnostic-session-to-recommendation.md)
