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
| VR-001 | `/admin` | Workspace | Core | Executive Dashboard | P0 | Twig | QA |
| VR-002 | `/sales/dashboard` | Workspace | Sales | Domain Dashboard | P0 | Twig | BACKLOG |
| VR-003 | `/sales/leads` | Workspace | Sales | Collection | P0 | Twig | BACKLOG |
| VR-004 | `/sales/deals/{id}` | Workspace | Sales | Entity Workspace | P0 | Twig | BACKLOG |

VR-001 уже відрізаний від legacy PHTML і page-specific Vite entrypoint. Статус стане `DONE` після production CI та merge.

## Зменшення legacy

Стартовий Wave 13 baseline для canonical Symfony visual layer:

- нові `tn-*` primitives: 0;
- inline browser handlers: 0;
- canonical inline-style debt: не більше 12.

VR-001 додатково видаляє один production PHTML screen і один page-specific frontend entrypoint.
