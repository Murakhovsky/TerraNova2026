---
title: Wave 13 — Golden Four consolidation
description: Доказова база та нормалізаційний контракт Phase 2.5 для чотирьох production archetypes перед підвищенням visual stability.
status: active
updated: 2026-09-23
kind: architecture
---

# Wave 13 — консолідація Golden Four

Phase 2.5 не розширює visual system новими сімействами. Вона звіряє executable contracts із чотирма production routes, які вже пройшли migration.

## Матриця доказів

| Unit | Route | Archetype | Production projection |
| --- | --- | --- | --- |
| VR-001 | `/admin` | Executive Dashboard | PageHeader + KpiStrip + EntityList + Empty/Error states |
| VR-002 | `/sales/dashboard` | Domain Dashboard | PageHeader + KpiStrip + EntityList + Empty/Error states |
| VR-003 | `/sales/leads` | Collection | PageHeader + FilterBar + EntityList + ActionBar + Empty/Error states |
| VR-004 | `/sales/deals/{id}` | Entity Workspace | WorkspaceHeader + EntityHeader + KpiStrip + Workspace context/activity/AI slots + ActionBar + Timeline + Error state |

## Правила консолідації

1. `PagePresentation` перелічує лише patterns, які реально входять у production composition.
2. Indirect Workspace patterns мають доказ у `CosWorkspace`, а не лише рядок у controller.
3. Golden Four templates мають archetype/state/density markers і не містять `tn-*`, inline styles або inline scripts.
4. Legacy PHTML ownership для всіх чотирьох units залишається видаленим.
5. Stability promotion відбувається лише після окремої responsive/state/accessibility normalization.

Executable evidence: `tests/architecture/web_experience_wave13_golden_four_consolidation.php`.

## Нормалізація адаптивності

Golden Four не вводить окремих route breakpoints.

- PageHeader, EntityHeader, EntityList і action/filter composition використовують canonical mobile behavior з `business-patterns.css`.
- page grid переходить до single-column через `layout.css`.
- Entity Workspace використовує фактичний `workspace-platform.css` contract: desktop context rail, tablet rail під main, mobile single-column rail + mobile workspace actions.
- `ContextPanel` більше не декларує неіснуючий drawer dependency.

## Докази станів

- Executive Dashboard: normal, permission denied, error, KPI/list empty states.
- Domain Dashboard: normal, error і empty collection regions.
- Collection: normal, error, empty.
- Entity Workspace: normal, error, empty communications/timeline regions.

SSR loading не симулюється декоративним skeleton лише заради матриці states.

## Докази доступності

Canonical Workspace Context / Activity / AI panels мають власні `aria-labelledby` regions.

Authenticated browser evidence: `tests/browser/golden_four_accessibility.mjs`.

Suite перевіряє desktop 1440×1000 і mobile 390×844 для:

- `/admin`;
- `/sales/dashboard`;
- `/sales/leads`;
- реального `/sales/deals/{id}`, знайденого через fixture Pipeline.

Для кожного target перевіряються canonical archetype marker, horizontal overflow і axe-core WCAG 2.x/2.1/2.2 A/AA violations.

Запуск входить у authenticated Sales `workflow_dispatch` перед mutation E2E та зберігає JSON evidence artifact. Stability promotion не повинна трактувати public-only PHASE 15 axe як доказ Golden Four.

## Політика першої стабілізації

Phase 2.5 не переводить весь Visual System у `stable`.

Стабільними стають лише archetypes, доведені Golden Four:

- Executive Dashboard;
- Domain Dashboard;
- Collection;
- Entity Workspace.

Для stable archetype:

1. усі fixed required Patterns мають бути `stable`;
2. кожна required Pattern group має мати щонайменше один `stable` шлях;
3. optional Patterns можуть лишатися `experimental` і не входять у stable guarantee;
4. top-level state matrix містить лише стани, реально доведені production ViewModel/Controller/Twig flow.

Перший stable Pattern subset:

- PageHeader;
- WorkspaceHeader;
- EntityHeader;
- KpiStrip;
- FilterBar;
- EntityList;
- EmptyState;
- ErrorState.

`DataGrid + Toolbar` залишаються experimental альтернативою Collection. `ActionBar`, `ContextPanel`, `Timeline` та інші optional Patterns також не отримують stability автоматично лише через один production use.

`PagePresentationFactory` після Phase 2.5 відхиляє Pattern, якого немає серед required, optional або required-group contracts archetype.
