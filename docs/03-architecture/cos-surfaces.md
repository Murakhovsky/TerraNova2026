---
title: Ієрархія поверхонь COS — PHASE 5
description: Канонічна модель Canvas → Surface → Subtle → Raised → Floating → Overlay для всіх COS themes, components і Domains.
status: active
updated: 2026-09-21
kind: architecture
---

# Ієрархія поверхонь COS — PHASE 5

## Мета

PHASE 5 визначає, як COS передає структурну глибину інтерфейсу.

Основний принцип:

> Спочатку contrast + border + spacing. Shadow з'являється лише коли елемент справді знаходиться над іншим UI.

Це прибирає "dashboard із плаваючих карток" і робить інтерфейс точнішим, щільнішим та преміальнішим.

## Канонічна ієрархія

```text
Canvas
  ↓
Default Surface
  ↓
Subtle Surface
  ↓
Raised Surface
  ↓
Floating Surface
  ↓
Overlay Surface
```

Це не шість різних декоративних стилів. Це шість семантичних рівнів.

## Рівень Canvas

Canvas є базовим фоном Workspace.

Використання:

- page background;
- workspace background;
- області між великими structural sections.

Canvas:

- не має border;
- не має shadow;
- не є card.

## Базова поверхня

Default Surface є основною робочою поверхнею.

Використання:

- panels;
- cards;
- forms;
- tables;
- workspace sections;
- entity content.

Default Surface:

- має structural border, де потрібна межа;
- не має elevation shadow за замовчуванням.

## Приглушена поверхня

Subtle Surface використовується для внутрішньої ієрархії.

Приклади:

- table header;
- secondary metadata group;
- selected contextual section;
- nested informational region;
- quiet state container.

Subtle не означає "ще одна card".

## Піднята поверхня

Raised Surface виділяє поверхню контрастом, але все ще лишається частиною layout.

Приклади:

- emphasized local panel;
- selected summary;
- temporary local state;
- high-priority content area.

Raised Surface не отримує великий floating shadow.

Різниця повинна бути видима навіть якщо shadows вимкнені.

## Плаваюча поверхня

Floating Surface справді знаходиться над основним layout.

Використання:

- dropdown;
- popover;
- autocomplete menu;
- column menu;
- command suggestions;
- floating toolbar.

Floating може використовувати restrained shadow.

## Накладна поверхня

Overlay Surface належить до верхнього interaction layer.

Використання:

- modal;
- drawer;
- command palette;
- Activity Center;
- AI side panel;
- blocking confirmation.

Overlay має:

- overlay/backdrop;
- найсильніший дозволений shadow;
- чіткий focus/interaction boundary.

## Канонічні токени

Кольори:

```text
--cos-surface-canvas-bg
--cos-surface-default-bg
--cos-surface-subtle-bg
--cos-surface-raised-bg
--cos-surface-floating-bg
--cos-surface-overlay-bg
--cos-surface-border
--cos-surface-border-strong
--cos-surface-backdrop
```

Shadows:

```text
--cos-surface-canvas-shadow
--cos-surface-default-shadow
--cos-surface-subtle-shadow
--cos-surface-raised-shadow
--cos-surface-floating-shadow
--cos-surface-overlay-shadow
```

Persistent layout surfaces мають `none` shadow за замовчуванням.

## Відповідність компонентів

### Постійні layout-поверхні

```text
Workspace header       → Default / Raised by contrast
Workspace panel        → Default
Form section           → Default
DataGrid               → Default
DataGrid header        → Subtle
Entity card            → Default
Metric group           → Default / Subtle
AI result card         → Default / Subtle
```

### Плаваючі елементи

```text
Dropdown               → Floating
Popover                → Floating
Autocomplete           → Floating
DataGrid column menu   → Floating
Workspace action menu  → Floating
Toast                  → Floating
```

### Накладні елементи

```text
Modal                  → Overlay
Drawer                 → Overlay
Command Palette        → Overlay
Activity Center        → Overlay
AI Panel               → Overlay
Critical confirmation  → Overlay
```

## Картки

Card не є surface level.

Card є component composition, який зазвичай використовує Default Surface.

`raised` variant не повинен означати "додай великий box-shadow". Після PHASE 5 він означає вищий surface contrast.

Це прибирає анти-патерн:

```text
Card
└ Card
  └ Card
    └ Card
```

## Інтеграція з Bootstrap

Bootstrap залишається mechanics layer.

COS surface bridge централізовано задає surface semantics для:

- Card;
- Dropdown;
- Modal;
- Offcanvas;
- Toast;
- Popover.

Bootstrap component не може самостійно визначити elevation COS.

## Теми оформлення

Light, Dark, Origin та Glass можуть мати різний material character:

- opacity;
- color;
- blur;
- saturation;
- border contrast;
- ambient lighting.

Але semantic level не змінюється.

Dropdown залишається Floating і в Light, і в Glass.

Modal залишається Overlay і в Origin, і в Dark.

## Режим Glass

Glass може робити Surface/Floating/Overlay напівпрозорими.

Але hierarchy повинна лишатися помітною через:

- opacity;
- border;
- blur;
- shadow strength;
- background contrast.

Принцип PHASE 2 зберігається:

> glass for structure, opacity for information.

## Правило тіней

Shadow дозволено за замовчуванням для:

- Floating;
- Overlay.

Shadow не використовується для:

- Canvas;
- Default Surface;
- Subtle Surface;
- звичайного Raised Surface.

Виняток потребує конкретного interaction/use-case, а не аргументу "так красивіше".

## Правила для Domain UI

Domain не створює власну elevation scale.

Заборонено:

```text
--sales-card-shadow
--finance-elevation-3
--property-floating-bg
```

Domain використовує canonical surface level або створює спеціалізовану visualization surface з явним обґрунтуванням.

## Критерії завершення PHASE 5

PHASE 5 завершена, коли:

- semantic surface tokens існують;
- surface hierarchy має reusable CSS primitives;
- Bootstrap surfaces підпорядковані COS;
- persistent `.cos-card` не має shadow за замовчуванням;
- raised card передає hierarchy через background/contrast, а не elevation;
- dropdown/popover/toast використовують Floating;
- modal/drawer/command/AI/activity panels використовують Overlay;
- `/dev/ui` візуально показує всі шість рівнів;
- CI перевіряє ключові mapping contracts;
- Themes не створюють альтернативну surface hierarchy.
