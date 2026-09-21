---
title: Канонічний visual pass primitives COS
description: Правила PHASE 6 для станів controls, signals, surfaces і navigation primitives у COS Experience Platform.
status: active
updated: 2026-09-21
kind: architecture
---

# Канонічний visual pass primitives COS — PHASE 6

## Мета

PHASE 6 не створює новий frontend foundation і не дублює наявні компоненти.

Вона проходить наявні canonical primitives та фіксує одну візуальну граматику станів:

```text
default
hover
focus
active
selected
disabled
loading
error
```

Business state при цьому залишається у ViewModel, UIAction, Workflow та Application layer. CSS state не стає business logic.

## Controls

Канонічні controls: Button, IconButton, Input, Select, Textarea, Checkbox, Radio і Switch.

Button та IconButton отримують backward-compatible loading і pressed semantics: `aria-busy`, native `disabled` під час loading, optional `aria-pressed`, visible progress spinner і текстовий loading label.

Input, Select і Textarea мають спільні hover, focus, disabled та error правила.

## Signals

Канонічні signals: Badge, Status, Alert, Progress, Spinner і Skeleton.

`CosStatus` є compact system signal. Він не замінює майбутній business-specific Stage/Status pattern з PHASE 7.

Status завжди має textual label і shape signal, тому семантика не передається лише кольором.

## Surfaces

Card не отримує нову декоративну мову.

Selected surface використовує ті самі semantic state tokens. Panel залишається composition поверх canonical surface hierarchy з PHASE 5, а не другим клоном Card.

Modal, Drawer, Popover, Tooltip і Dropdown продовжують використовувати Floating/Overlay surface contracts.

## Navigation

Tabs, Dropdown та shell navigation використовують одну selected/active/focus grammar.

Command Palette, Sidebar і Topbar не отримують локальні палітри чи радіуси: вони наслідують shell, surface і state tokens.

## Semantic state tokens

PHASE 6 вводить:

```text
--cos-state-hover-bg
--cos-state-active-bg
--cos-state-selected-bg
--cos-state-selected-border
--cos-state-focus-border
--cos-state-error-border
--cos-state-disabled-opacity
--cos-state-loading-opacity
```

Domain CSS не створює власні аналоги цих станів.

## Deterministic visual QA

`data-cos-state` є inspection/test hook для `/dev/ui` та visual regression.

Він дозволяє стабільно показати hover/focus/active/selected у screenshot tests без симуляції випадкового pointer state.

Application business logic не повинна керувати lifecycle через цей attribute.

## Accessibility

Стан компонента має дублюватися native/ARIA semantics там, де це доречно:

- disabled → `disabled` + `aria-disabled`;
- loading → `aria-busy`;
- selected toggle → `aria-pressed`;
- invalid field → `aria-invalid`;
- focus → visible focus ring;
- status → text + dot/shape + semantic tone.

`prefers-reduced-motion` вимикає декоративне обертання control spinner.

## Критерії завершення PHASE 6

PHASE 6 завершена, коли:

- state matrix присутня у `/dev/ui`;
- Button і IconButton підтримують disabled/loading/pressed semantics;
- field error/focus/disabled states є канонічними;
- Checkbox, Radio і Switch мають selected/disabled/focus behavior;
- Status існує як canonical signal primitive;
- Tabs і Dropdown використовують selected/active/focus grammar;
- selected surface використовує semantic tokens;
- visual test hook не містить business logic;
- architecture gate блокує видалення state contract;
- runtime Design System smoke реально рендерить PHASE 6 catalog.
