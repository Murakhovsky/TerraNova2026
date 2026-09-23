---
title: Wave 13 — Трекер візуальної міграції
description: Поточний стан production migration units COS Visual Rebuild із окремим відстеженням archetype, Twig cutover і legacy cleanup.
status: active
updated: 2026-09-23
kind: architecture
---

# Wave 13 — Трекер візуальної міграції

## Поточний стан

| ID | Route | Surface | Domain | Archetype | Priority | Target | Status |
| --- | --- | --- | --- | --- | --- | --- | --- |
| VR-001 | `/admin` | Workspace | Core | Executive Dashboard | P0 | Twig | DONE |
| VR-002 | `/sales/dashboard` | Workspace | Sales | Domain Dashboard | P0 | Twig | DONE |
| VR-003 | `/sales/leads` | Workspace | Sales | Collection | P0 | Twig | DONE |
| VR-004 | `/sales/deals/{id}` | Workspace | Sales | Entity Workspace | P0 | Twig | DONE |

VR-001 завершений і змерджений у `main`: `/admin` більше не має legacy PHTML ownership або page-specific Vite entrypoint.

VR-002 уже нормалізований як Domain Dashboard: окремий ViewModel/Presenter, PagePresentation contract, PageHeader і реальний KpiStrip без нового page-specific CSS.

VR-003 використовує Collection archetype з `EntityList + FilterBar` як валідною альтернативою `DataGrid + Toolbar`. Це зафіксовано executable required pattern groups, а не локальним винятком для Sales.

VR-004 завершений: Deal Workspace працює через `CosWorkspace + EntityHeader`, окремий ViewModel/Presenter і Stimulus runtime; legacy PHTML ownership видалено.

Phase 2.5 консолідує Golden Four і вводить вибіркову stability policy: стабілізуються лише чотири доведені archetypes та мінімальний Pattern core, решта Visual System лишається experimental.

## Зменшення legacy

Стартовий Wave 13 baseline для canonical Symfony visual layer:

- нові `tn-*` primitives: 0;
- inline browser handlers: 0;
- canonical inline-style debt: не більше 12.

VR-001 додатково видаляє один production PHTML screen і один page-specific frontend entrypoint.
