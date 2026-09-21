---
title: Канонічна геометрія COS — PHASE 4
description: Заморожений geometry contract COS, який визначає радіуси, border width, semantic geometry roles та bridge до Bootstrap.
status: active
updated: 2026-09-21
kind: architecture
---

# Канонічна геометрія COS — PHASE 4

## Мета

PHASE 4 заморожує геометрію базового UI COS.

Bootstrap не є власником візуальної геометрії. Він надає browser/UI mechanics, але його geometry variables підпорядковуються COS semantic tokens.

Канонічний ланцюг:

```text
COS geometry tokens
        ↓
semantic geometry roles
        ↓
Bootstrap geometry bridge
        ↓
canonical COS components
        ↓
Domains
```

## Базова шкала

```text
xs      4 px
sm      6 px
md      8 px
lg     10 px
pill  999 px
```

Ця шкала є глобальною.

Theme не змінює базову geometry scale.

## Семантичні ролі

### Елементи керування

Button, Input, Select, Search, IconButton та подібні controls:

```text
--cos-radius-control = 6 px
```

Small compact controls можуть використовувати:

```text
--cos-radius-control-sm = 4 px
```

### Панелі

Card, Panel, Data surface, Dropdown, Toast, Alert:

```text
--cos-radius-panel = 8 px
```

### Накладні поверхні

Modal та великі floating overlays:

```text
--cos-radius-overlay = 10 px
```

### Капсульні елементи

Badge, tag, status chip, compact state indicator та справді pill-shaped control:

```text
--cos-radius-pill = 999 px
```

Pill не використовується як універсальний спосіб зробити інтерфейс "м'якшим".

## Товщина межі

Канонічний structural border:

```text
--cos-border-width = 1 px
```

Focus ring, selected outline або special emphasis не є structural border і можуть мати іншу товщину через окремий interaction contract.

## Інтеграція з Bootstrap

Bootstrap CSS завантажується до COS `app.css`.

Після Bootstrap COS встановлює:

- `--bs-border-width`;
- `--bs-border-radius`;
- `--bs-border-radius-sm`;
- `--bs-border-radius-lg`;
- `--bs-border-radius-xl`;
- `--bs-border-radius-xxl`;
- `--bs-border-radius-pill`.

Також canonical geometry bridge перевизначає component-level variables для:

- Button;
- Card;
- Dropdown;
- Modal;
- Alert;
- Toast;
- Popover;
- Tooltip;
- Nav Pills.

Отже Bootstrap автоматично успадковує COS geometry, а не диктує її.

## Контракт тем оформлення

`Light`, `Dark` і майбутній canonical `Origin` не повинні змінювати geometry scale.

Зміна theme означає appearance:

- color;
- surface;
- contrast;
- shadow;
- atmosphere;
- blur.

Вона не означає іншу базову форму component tree.

## Експериментальні стилі та Glass

PHASE 2 дозволяв Glass експериментально збільшувати global radii.

Після PHASE 4 це прибирається.

Glass лишається виразним через:

- translucency;
- blur;
- saturation;
- depth;
- ambient light;
- border contrast.

Але Button, Panel, Modal та інші canonical surfaces зберігають одну geometry grammar з рештою COS.

Це важливо для cross-theme consistency і для того, щоб Glass не перетворився на окремий design system.

## Домени

Domain не може визначати власну глобальну шкалу:

```text
--sales-radius-*
--finance-radius-*
--property-radius-*
```

і не повинен використовувати випадкові:

```css
border-radius: 13px;
border-radius: 17px;
border-radius: 1.4rem;
```

Допустимі винятки:

- `border-radius: 0` для edge-to-edge mobile/fullscreen surface;
- геометрично обґрунтовані visualization primitives;
- circles через canonical pill/shape token;
- новий global geometry contract через окрему architecture change.

## Що не заморожується

PHASE 4 не заморожує:

- spacing;
- typography;
- color;
- shadow;
- visual density;
- layout composition;
- charts;
- maps;
- spatial/3D visualization.

Це окремі contracts.

## Критерії завершення PHASE 4

PHASE 4 завершена, коли:

- geometry scale зафіксована у semantic tokens;
- semantic roles control/panel/overlay існують;
- Bootstrap bridge використовує COS geometry;
- `geometry.css` завантажується до canonical component CSS;
- Glass більше не перевизначає global radius scale;
- довільні numeric `border-radius` у canonical Symfony CSS блокуються CI;
- Domain не може створити альтернативний global radius token family без свідомої architecture change.
