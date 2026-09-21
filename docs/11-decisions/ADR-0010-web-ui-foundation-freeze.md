---
title: ADR-0010 - Замороження Web/UI foundation перед розвитком візуальної системи COS
description: Рішення зафіксувати поточний Symfony Web Experience Platform як канонічну UI foundation і розвивати стиль еволюційно поверх semantic tokens та canonical components.
status: accepted
updated: 2026-09-21
kind: decision
---

# ADR-0010 - Замороження Web/UI foundation перед розвитком візуальної системи COS

## Контекст

Wave 12.0-12.15 сформували достатню Web Experience Platform для системної роботи над продуктом:

- Symfony SSR і Twig;
- Twig Components;
- Symfony UX, Stimulus, Turbo і Live Components;
- Bootstrap 5 як browser/layout mechanics;
- semantic `--cos-*` design tokens;
- canonical Workspace Shell;
- Unified UIAction;
- Interaction Components;
- Forms Platform;
- Data Platform;
- Workspace Platform;
- Realtime і Async Operations UI;
- AI UI;
- Search і Command Palette;
- responsive/mobile contracts;
- manager-only reference surfaces `/dev/ui` і `/dev/workspace`.

До цього моменту зміна стилю часто вимагала втручання у foundation. Після Wave 12 це більше не є нормальною практикою.

Подальший розвиток COS повинен зосередитися на візуальній мові, компонентах, бізнес-патернах і product surfaces, а не на черговій заміні frontend architecture.

## Рішення

**Поточна Symfony Web Experience Platform заморожується як канонічна UI foundation COS.**

Візуальний розвиток COS виконується поверх неї через:

```text
Semantic tokens
    ↓
Canonical primitives
    ↓
Canonical interaction/data/form/workspace components
    ↓
Business UI patterns
    ↓
Domain-owned content
```

Зміна кольорів, геометрії, типографіки, spacing, density, motion, surface hierarchy, visual states або component styling **не є підставою** для заміни Web runtime.

## Канонічний стек після freeze

Стандартний бізнес-UI:

```text
Symfony Controller / Application Query
        ↓
Twig SSR
        ↓
Twig Components
        ↓
Turbo / Live Components
        ↓
Stimulus
        ↓
Mercure / Turbo Streams
```

Browser/layout mechanics:

- Bootstrap 5;
- Popper;
- Symfony UX adapters;
- canonical COS CSS.

Дозволені спеціалізовані adapters:

- Chart.js для charts;
- Tabulator для advanced grids, якщо server-first DataGrid недостатній;
- FullCalendar для calendar surfaces;
- Flatpickr для спеціалізованого date/time input;
- SortableJS для drag-and-drop;
- Cytoscape для graph/visualization surfaces;
- Three.js для spatial/3D surfaces.

Вони не визначають архітектуру стандартного business UI.

## Заборонені паралельні foundation

Без нового ADR не можна вводити як загальну основу:

- React / ReactDOM;
- Vue;
- Angular;
- Svelte;
- Alpine;
- jQuery;
- інший SPA framework;
- другий design-token runtime;
- окремий Domain-level design system;
- окремий global navigation shell;
- client-side business state як альтернативу Application/Domain state.

Транзитивна бібліотечна залежність не вважається новою foundation, якщо Domain/UI код не використовує її як product runtime. Наприклад, Preact усередині FullCalendar не робить Preact канонічним UI framework COS.

## Що Domain може робити

Domain може додавати:

- product-owned Twig content;
- domain-specific visualization;
- composition/layout для свого use case;
- module-owned workspace extensions;
- domain-specific status semantics;
- special charts або data presentation;
- browser adapter, якщо він ізольований і має чіткий use case.

Domain не може перевизначати:

- Button;
- Input;
- Select;
- Modal;
- Drawer;
- Toast;
- DataGrid foundation;
- Workspace Shell;
- global typography;
- global spacing;
- global radii;
- global color system;
- global responsive breakpoints;
- global action semantics.

## Правило відхилення

Нова frontend technology допускається лише коли одночасно виконано:

1. існує конкретний use case, який не покривається поточною foundation;
2. проведено порівняння із Twig/Stimulus/LiveComponent/JS Island;
3. визначено ownership;
4. визначено bundle/runtime cost;
5. визначено accessibility і mobile impact;
6. створено окремий ADR;
7. CI architecture gate оновлено свідомо в тому самому change set.

Фраза "так сучасніше" не є технічним обґрунтуванням. Людство вже достатньо разів переписувало admin panel заради нового JavaScript framework.

## Візуальні зміни після freeze

Візуальні експерименти дозволені й очікувані.

Їхній порядок:

```text
Visual Constitution
↓
Style Lab
↓
Theme tokens
↓
Canonical component visual pass
↓
Canonical screens
↓
Domain rollout
```

Експеримент не повинен вимагати зміни business contracts або duplication component tree.

## Критерії завершення PHASE 0

Foundation вважається замороженою, якщо:

- ADR прийнятий;
- canonical runtime зафіксований;
- заборонені паралельні frameworks перевіряються CI;
- required Symfony UI stack перевіряється CI;
- visual work не потребує нової application architecture;
- Domain UI extensions лишаються module-owned;
- нова frontend foundation потребує окремого ADR.

## Наслідки

Позитивні:

- дизайн можна перебирати швидко й дешево;
- Domains автоматично успадковують покращення;
- менший ризик фрагментації;
- UI інвестиції накопичуються, а не списуються після наступного rewrite;
- команда може відрізняти visual debt від architecture debt.

Компроміс:

- окремі складні surfaces іноді вимагатимуть JS Island;
- canonical components доведеться розвивати дисципліновано;
- швидкий локальний CSS hack інколи буде дорожчим за правильне platform-level рішення.

Це свідомий компроміс.
