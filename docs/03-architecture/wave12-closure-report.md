---
title: Wave 12 — Final Closure Report
description: Фінальний acceptance record для Web & Experience Platform після Wave 12.0–12.26, production Sales cutover і Web Platform v1 freeze.
status: closed
updated: 2026-09-22
kind: architecture
---

# Wave 12 — CLOSED

Wave 12 завершена як горизонтальна **COS Web & Experience Platform v1**.

Closure не означає, що кожен legacy screen COS переписаний на Twig. Це ніколи не було ціллю Wave 12. Closure означає, що platform contracts, reference vertical, production cutover, quality gates і change-control достатні, щоб наступні Domains інтегрувалися у стабільний Web runtime без створення власного frontend stack.

## Acceptance scope

Виконаний canonical execution plan **12.0–12.26**:

| Діапазон | Результат |
| --- | --- |
| 12.0–12.2 | architecture, Symfony UX runtime, UI Toolkit та governed adapters |
| 12.3–12.6 | Design System, Shell, extension runtime, unified UIAction |
| 12.7–12.10 | interaction components, forms, data platform, workspace platform |
| 12.11–12.15 | workflow actions, realtime, async operations, AI UI, search/commands |
| 12.16–12.18 | mobile, PWA, native-ready contracts |
| 12.19–12.22 | security, feature flags, audit/history, UI Catalog |
| 12.23–12.24 | testing, browser evidence, accessibility, performance, observability |
| 12.25 | Sales reference vertical |
| 12.26 | production Sales Dashboard / Lead List / Lead Workspace cutover |

## Final quality closure

Wave 12.23 originally kept a source-controlled Panther suite as readiness evidence while Playwright was the executable browser layer.

Final closure removes that exception:

- Panther test is no longer wrapped in a `class_exists()` readiness guard;
- CI runs real **Panther E2E** against the already-started canonical runtime;
- the browser dependency is isolated from production and pinned to `symfony/panther:2.4.0`;
- production Symfony image remains `composer install --no-dev`;
- Playwright remains the broader desktop/mobile/visual/accessibility browser quality layer;
- PHASE 15 axe-core WCAG evidence remains additive, not replaced by Panther.

This closes the last literal testing gap in the Wave 12 Definition of Done without polluting runtime dependencies.

## Production reference proof

Wave 12.26 moved the reference Sales slice to canonical production routes:

- `/sales/dashboard`;
- `/sales/leads`;
- `/sales/leads/{id}`.

The reference slice proves the platform against real QueryBus/Application behavior, permissions, tenant isolation, mobile composition, realtime, AI context and audit semantics.

## Freeze

ADR-0011 is accepted. Web Experience Platform v1 public contracts are frozen and protected by architecture/change-control gates.

After this closure:

1. fundamental Web Platform contract changes require ADR-governed evolution;
2. new Domains must consume the canonical extension/workspace/action/component model;
3. product-specific UI may evolve without reopening Wave 12 when it does not break frozen contracts;
4. remaining PHTML/legacy screen migrations are **production adoption work**, not unfinished Wave 12 foundation.

## Explicitly outside closure debt

The following work may continue, but does not reopen Wave 12:

- migration of remaining Sales Today/Pipeline/Deals/Deal Workspace/Director/Admin surfaces;
- Property, Client Case, Administration, Growth, Service and later Domain adoption;
- visual refinements and new canonical components;
- performance/accessibility tuning;
- optional new capabilities added without parallel frontend foundations.

## Final state

```text
Wave 12
  Architecture       CLOSED
  Runtime            CLOSED
  UI Platform        CLOSED
  Realtime / Async   CLOSED
  AI UI              CLOSED
  Mobile / PWA       CLOSED
  Native-ready       CLOSED
  Security           CLOSED
  Quality            CLOSED
  Panther E2E        CLOSED
  Sales Reference    CLOSED
  Sales Cutover      CLOSED
  Platform v1 Freeze ACCEPTED
```

Further work proceeds **on top of Web Platform v1**, not by extending the Wave 12 execution sequence.
