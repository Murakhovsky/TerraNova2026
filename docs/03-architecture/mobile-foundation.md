---
title: Мобільна основа Web
description: Канонічні mobile-first правила COS для viewport, safe-area, touch targets, Workspace actions, responsive reflow та browser boundaries.
status: active
updated: 2026-09-21
kind: architecture
---

# Мобільна основа Web

Wave 12.16 закриває mobile foundation як горизонтальну capability Experience Platform.

Мета не полягає у створенні окремої mobile application logic. Один server-rendered UI має коректно reflow-итися на вузькому viewport і використовувати ті самі Commands, Queries, UIActions та permissions.

## Канонічний потік

```text
TenantContext + EntityRef
        ↓
UIActionResolver
        ↓
WorkspaceViewModel
   ├─ desktop primary
   ├─ desktop secondary
   ├─ mobile primary
   └─ mobile menu
        ↓
same workspace-platform action event
        ↓
Application command boundary
```

Mobile surface не створює власну action registry.

## Viewport і safe-area

Base layout використовує:

```html
viewport-fit=cover
```

Canonical mobile CSS враховує:

- `env(safe-area-inset-top)`;
- `env(safe-area-inset-bottom)`;
- `100dvh` для dynamic browser chrome;
- fallback `100vh`.

Це особливо важливо для iOS Safari та installed PWA mode.

## Сенсорні цілі

Design System визначає:

```text
--cos-touch-target-min: 2.75rem
```

Це 44 px за стандартного root font size.

На mobile цей minimum застосовується до:

- Shell menu;
- icon actions;
- search trigger;
- bottom navigation;
- Workspace primary actions;
- menu actions;
- shared buttons.

Business UI не повинен зменшувати target заради вміщення більшої кількості controls.

## Мобільні дії Workspace

`WorkspaceCompositionResolver` один раз резолвить canonical `UIAction` list і проектує placements:

- `workspace.primary`;
- `workspace.secondary`;
- `mobile.primary`;
- `mobile.menu`.

На вузькому viewport desktop header action block ховається.

Mobile actions рендеряться у fixed surface над bottom navigation.

Кнопки використовують той самий:

```text
click->workspace-platform#action
```

Browser не отримує окрему mobile execution authority.

## Нижня навігація

Shell mobile navigation лишається global navigation layer.

Workspace mobile actions розташовані над нею, а не замінюють її.

Це розділяє:

- global navigation;
- contextual entity actions.

Content отримує достатній bottom padding, щоб fixed surfaces не перекривали останній інтерактивний елемент.

## Адаптивна перебудова

Mobile foundation спирається на вже реалізовані platform rules:

- DataGrid: semantic table → record cards;
- Forms: one-column + sticky safe-area action area;
- Drawer: full-width composition;
- Workspace rail: одна колонка;
- command palette: dynamic viewport;
- Activity Center / AI surface: mobile sheet/full-height constraints.

Responsive означає reprioritization, а не механічне зменшення desktop UI.

## Межа браузера

Заборонено:

- `navigator.userAgent` branching для business UI;
- `screen.width` як authority;
- окремий mobile REST client;
- localStorage/sessionStorage як business state;
- дублювати UIAction model для mobile;
- виконувати mutations через touch handler напряму;
- hardcode-ити Domain logic у responsive JavaScript.

Layout breakpoint належить CSS.

Application behavior залежить від capabilities та server context, а не від назви пристрою.

## Малий viewport

Для compact mobile використовується додаткова межа 390 px.

На ній дозволяється:

- зменшити horizontal spacing;
- скоротити label density;
- залишити minimum touch target без змін.

## Наступні хвилі

Wave 12.16 не робить COS PWA.

Вона лише готує правильний responsive/mobile runtime для:

- Wave 12.17 PWA;
- Wave 12.18 Native-ready bridge.

Service Worker, installability та native capability bridge належать наступним хвилям.

## Перевірка

CI перевіряє:

- `viewport-fit=cover`;
- canonical touch target token;
- `100dvh` + safe-area;
- mobile UIAction projections;
- Workspace mobile action surface;
- DataGrid/Form/Drawer mobile contracts;
- відсутність UA sniffing;
- runtime smoke `cos:web:mobile:smoke`.
