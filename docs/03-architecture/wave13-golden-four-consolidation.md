---
title: Wave 13 — Golden Four consolidation
description: Phase 2.5 evidence and normalization contract for the four production archetypes proven before visual stability promotion.
status: active
updated: 2026-09-23
kind: architecture
---

# Wave 13 — Golden Four consolidation

Phase 2.5 не розширює visual system новими сімействами. Вона звіряє executable contracts із чотирма production routes, які вже пройшли migration.

## Evidence matrix

| Unit | Route | Archetype | Production projection |
| --- | --- | --- | --- |
| VR-001 | `/admin` | Executive Dashboard | PageHeader + KpiStrip + EntityList + Empty/Error states |
| VR-002 | `/sales/dashboard` | Domain Dashboard | PageHeader + KpiStrip + EntityList + Empty/Error states |
| VR-003 | `/sales/leads` | Collection | PageHeader + FilterBar + EntityList + ActionBar + Empty/Error states |
| VR-004 | `/sales/deals/{id}` | Entity Workspace | WorkspaceHeader + EntityHeader + KpiStrip + Workspace context/activity/AI slots + ActionBar + Timeline + Error state |

## Consolidation rules

1. `PagePresentation` перелічує лише patterns, які реально входять у production composition.
2. Indirect Workspace patterns мають доказ у `CosWorkspace`, а не лише рядок у controller.
3. Golden Four templates мають archetype/state/density markers і не містять `tn-*`, inline styles або inline scripts.
4. Legacy PHTML ownership для всіх чотирьох units залишається видаленим.
5. Stability promotion відбувається лише після окремої responsive/state/accessibility normalization.

Executable evidence: `tests/architecture/web_experience_wave13_golden_four_consolidation.php`.
