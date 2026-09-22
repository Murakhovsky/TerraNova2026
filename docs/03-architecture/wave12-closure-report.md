---
title: Wave 12 — фінальний звіт про закриття
description: Фінальний запис приймання Web & Experience Platform після Wave 12.0–12.26, production Sales cutover і Web Platform v1 freeze.
status: closed
updated: 2026-09-22
kind: architecture
---

# Wave 12 — закрито

Wave 12 завершена як горизонтальна **COS Web & Experience Platform v1**.

Закриття не означає, що кожен legacy screen COS переписаний на Twig. Це ніколи не було ціллю Wave 12. Закриття означає, що platform contracts, reference vertical, production cutover, quality gates і change-control достатні, щоб наступні Domains інтегрувалися у стабільний Web runtime без створення власного frontend stack.

## Обсяг приймання

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

## Фінальне закриття якості

Wave 12.23 спочатку зберігав source-controlled Panther suite як readiness evidence, тоді як Playwright був executable browser layer.

Фінальне закриття прибирає цей виняток:

- Panther test більше не загорнутий у `class_exists()` readiness guard;
- CI запускає реальний **Panther E2E** проти вже запущеного canonical runtime;
- browser dependency ізольована від production і зафіксована як `symfony/panther:2.4.0`;
- production Symfony image і далі використовує `composer install --no-dev`;
- Playwright лишається ширшим desktop/mobile/visual/accessibility browser quality layer;
- PHASE 15 axe-core WCAG evidence лишається додатковим шаром і не замінюється Panther.

Це закриває останню буквальну прогалину тестування у Definition of Done Wave 12 без забруднення runtime dependencies.

## Доказ на production-вертикалі

Wave 12.26 перевів reference Sales slice на canonical production routes:

- `/sales/dashboard`;
- `/sales/leads`;
- `/sales/leads/{id}`.

Reference slice перевіряє платформу на реальній QueryBus/Application поведінці, permissions, tenant isolation, mobile composition, realtime, AI context та audit semantics.

## Замороження платформи

ADR-0011 прийнятий. Public contracts Web Experience Platform v1 заморожені та захищені architecture/change-control gates.

Після цього закриття:

1. фундаментальні зміни Web Platform contracts потребують ADR-governed evolution;
2. нові Domains мають використовувати canonical extension/workspace/action/component model;
3. product-specific UI може еволюціонувати без повторного відкриття Wave 12, якщо frozen contracts не ламаються;
4. решта PHTML/legacy screen migrations є **production adoption work**, а не незавершеним фундаментом Wave 12.

## Роботи поза боргом закриття

Такі роботи можуть продовжуватися, але не відкривають Wave 12 знову:

- migration решти Sales Today/Pipeline/Deals/Deal Workspace/Director/Admin surfaces;
- Property, Client Case, Administration, Growth, Service та подальший Domain adoption;
- visual refinements і нові canonical components;
- performance/accessibility tuning;
- optional capabilities без створення паралельної frontend foundation.

## Фінальний стан

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

Подальша робота виконується **поверх Web Platform v1**, а не шляхом продовження execution sequence Wave 12.
