---
title: Wave 13 — Міграція візуальної системи COS
description: Канонічний контракт перенесення production surfaces на заморожену COS Web Experience Platform v1 без створення нового frontend runtime.
status: active
updated: 2026-09-23
kind: architecture
---

# Wave 13 — Міграція візуальної системи COS

## Обсяг

Wave 13 **не** вводить новий frontend runtime. Wave 12 залишається замороженою Web Experience Platform v1.

Wave 13 переносить production surfaces з page-oriented legacy composition на:

```text
Application Query
  ↓
Read Model
  ↓
Page ViewModel
  ↓
Page Archetype
  ↓
Reusable Patterns
  ↓
COS Components
  ↓
COS Visual System
```

Одиницею дизайну більше не є окремий route. Route обирає archetype і передає domain data/actions.

## Власність

```text
symfony/src/Web/Experience/
├── Archetype/   контракти сімейств сторінок
├── Pattern/     reusable composition contracts
├── Component/   канонічні low-level/composite components
├── Workspace/   workspace composition/runtime
├── Action/      governed UIAction presentation
└── Visual/      спільні visual-system contracts

symfony/assets/styles/
├── tokens.css
├── typography.css
├── geometry.css
├── surfaces.css
├── layout.css
├── primitives.css
├── business-patterns.css
├── workspace-platform.css
└── shell.css
```

Production route може додавати Domain Components, але не може створювати другу generic component system.

## Життєвий цикл Archetype

Усі Wave 13 archetypes і patterns починають зі статусу `experimental`.

```text
experimental
  ↓
Golden Four usage
  ↓
Phase 2.5 consolidation
  ↓
stable
```

Статус `stable` не надається, доки Golden Four не перевірять Executive Dashboard, Domain Dashboard, Collection і Entity Workspace.

## Канонічні Page Archetypes

Executable registry: `PageArchetypeRegistry`.

Він містить 12 сімейств: Executive Dashboard, Domain Dashboard, Operational Queue, Collection, Entity Workspace, Process / Pipeline, Form / Editor, Map / Spatial, System / Control Surface, Portal, Public Catalog, Public Detail / Marketing.

Кожен archetype визначає surface, required/optional patterns, стандартні states, density support, responsive contract і stability.

## Контракт Pattern

Executable registry: `PatternRegistry`.

Кожен pattern оголошує name, purpose, props, slots, variants, sizes, states, responsive behavior, accessibility rules, dependencies, owner і stability.

Generic patterns залишаються domain-neutral.

## Контракт Layout

Page composition використовує semantic layout tokens і `layout.css`.

Канонічна власність охоплює максимальну ширину page/content/reading, sidebar/context width, page padding, section spacing, gutters, responsive breakpoints і shell heights.

Route-specific stylesheet не володіє цими значеннями.

## Базова лінія governance

На старті Wave 13.0 canonical Symfony surfaces мають:

- `tn-*` selectors/tokens: **0**;
- inline event handlers: **0**;
- inline `style=` usages: **12**.

12 inline styles є наявним боргом, а не дозволом для нового коду. CI трактує 12 як ratcheting upper bound, доки наступні міграції не зменшать його.

Legacy `frontend/` і `app/Interfaces/Web/View/` вимірюються окремо, бо це migration sources, а не canonical visual ownership.

## Архітектурні gates

Wave 13 CI падає, якщо canonical Symfony visual code вводить `tn-*`, inline event handlers, перевищує inline-style baseline, обходить layout tokens, тягне Domain/persistence dependencies у visual registry або передчасно робить archetype stable.

## Критерії завершення Wave 13.0

- існує executable Archetype Registry;
- існує executable Pattern Registry;
- існують canonical layout tokens і stylesheet;
- app stylesheet імпортує layout layer у детермінованому порядку;
- існує visual governance non-regression gate;
- archetypes/patterns залишаються experimental до Golden Four;
- заморожений Wave 12 runtime не змінено.
