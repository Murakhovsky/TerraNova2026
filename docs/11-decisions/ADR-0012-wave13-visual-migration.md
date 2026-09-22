---
title: ADR-0012 — Wave 13 додає Archetype і Pattern governance поверх Web Platform v1
description: Рішення мігрувати production pages через канонічні Page Archetypes, reusable Patterns і visual governance без заміни замороженого Wave 12 runtime.
status: accepted
updated: 2026-09-23
kind: decision
---

# ADR-0012 — Wave 13 додає Archetype і Pattern governance поверх Web Platform v1

## Контекст

ADR-0011 заморозив Web Experience Platform v1 після Wave 12. Платформа вже володіє Symfony SSR, Twig Components, Workspace, UIAction, Shell, DataGrid, Forms, Mobile/PWA та іншими runtime contracts.

Production routes досі мають різні покоління visual composition, включно з legacy PHTML/TN conventions і route-specific presentation. Мігрувати кожен route як окремий redesign означало б знову створити набір локальних UI мов.

## Рішення

Wave 13 вводить **visual composition governance** поверх Web Platform v1:

```text
Page
=
Archetype
+
Patterns
+
Domain Components
+
ViewModel
+
UIActions
```

Створюються два additive contracts:

1. `PageArchetypeRegistry` — канонічні сімейства production pages.
2. `PatternRegistry` — reusable композиції між Components і Page Archetypes.

Ці contracts не замінюють frozen Workspace/UIAction/Shell semantics Wave 12.

## Політика стабільності

Нові Archetypes і Patterns стартують як `experimental`.

Статус `stable` дозволений лише після Golden Four, Phase 2.5 consolidation, responsive/state/accessibility normalization та оновлення architecture gates.

Breaking change після stability проходить deprecation/migration або окремий ADR.

## Власність CSS

Wave 13 формалізує `layout.css` як єдиного власника page-level composition geometry.

Layout values походять із semantic tokens. Production pages не володіють власними max widths, sidebar/context widths, page padding, section spacing, breakpoint system або generic control geometry.

## Політика legacy

Legacy PHTML/TN code залишається migration source лише до cutover відповідної production unit.

Новий canonical Symfony visual code не може вводити `tn-*`, inline event handlers, нові inline styles або route-specific generic primitives.

CI використовує ratcheting baseline для боргу, який ще не видалено.

## Наслідки

Позитивні: приблизно 47 migration units більше не означають 47 дизайнів; Domains отримують predictable page families; reuse і responsive/state contracts стають перевірюваними.

Компроміс: production page більше не може «трошки особливо» винайти generic layout. Це навмисно.
