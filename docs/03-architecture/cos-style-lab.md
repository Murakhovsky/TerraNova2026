---
title: Лабораторія стилю COS — PHASE 2
description: Експериментальна лабораторія візуальних кандидатів Light, Dark, Origin A/B/C і Glass для вибору фірмової мови COS без зміни канонічної Web foundation.
status: active
updated: 2026-09-21
kind: architecture
---

# Лабораторія стилю COS — PHASE 2

## Мета

PHASE 2 переводить Візуальну конституцію COS з правил у порівнювані живі інтерфейси.

Style Lab не визначає фінальну production theme. Його задача — дати один і той самий реальний UI у кількох стилістичних кандидатах, щоб оцінювати характер, читабельність, преміальність і придатність до тривалої роботи.

## Кандидати

### Light як контрольний світлий режим

Поточний світлий COS є baseline.

Він потрібен для перевірки:

- максимальної читабельності;
- нейтрального office use;
- контрасту;
- щільних таблиць;
- forms;
- accessibility.

### Dark як контрольний темний режим

Поточний dark COS є другим baseline.

Він показує, чи нові кандидати дають реальну додану цінність, а не просто інший відтінок графіту.

### Origin A — точний графітовий стиль

Найсуворіший кандидат Calm Technical:

- graphite shell;
- cold neutral canvas;
- white precision surfaces;
- cobalt interaction signal;
- мінімум atmosphere;
- мінімум shadow.

Гіпотеза: найкраща довготривала основа для operational enterprise UI.

### Origin B — теплий executive-стиль

Той самий structural характер, але з дуже стриманим теплим ambient layer:

- graphite shell;
- mineral neutral workspace;
- amber atmosphere;
- cobalt interactions;
- трохи більше executive / money character.

Гіпотеза: преміальніший і людяніший вигляд без переходу в luxury UI.

### Origin C — холодний мінеральний стиль

Холодніший і футуристичніший кандидат:

- graphite + deep cobalt shell;
- blue-mineral canvas;
- cooler borders;
- clean synthetic surfaces;
- controlled spatial atmosphere.

Гіпотеза: сильніше відчуття "операційної системи нової епохи".

### Glass — преміальний скляний стиль

Свідомо максимальний glassmorphism candidate:

- translucent dark surfaces;
- backdrop blur;
- warm amber + cobalt ambient light;
- glass navigation;
- glass panels;
- glass overlays;
- збільшений radius;
- сильніший depth.

При цьому data-heavy елементи залишаються достатньо непрозорими для читання.

Гіпотеза: найсильніший premium/demo character, який може бути повноцінним appearance mode, якщо витримає DataGrid, Forms, mobile і довгу робочу сесію.

## Архітектурний принцип

Style Lab не змінює PHASE 0 foundation.

```text
production semantic tokens
        ↓
dev-only Style Lab overrides
        ↓
existing canonical components
        ↓
same business UI
```

Кандидати не мають:

- окремого component tree;
- окремих Domain templates;
- окремого business state;
- persistence;
- API;
- localStorage;
- окремого frontend runtime.

## Де тестувати

Основні reference surfaces:

- `/dev/ui`;
- `/dev/workspace`.

На обох поверхнях один switcher змінює стиль без reload.

## Матриця PHASE 2

На кожному кандидатові перевіряються:

- comfortable density;
- compact density;
- desktop;
- tablet;
- mobile;
- forms;
- DataGrid;
- Workspace;
- interaction overlays;
- async/realtime state;
- AI surface;
- financial metrics.

## Критерії оцінки

Кожен кандидат оцінюється за шкалою з однаковими питаннями:

1. Чи читається business hierarchy за 2–3 секунди?
2. Чи видно primary і next action?
3. Чи добре читаються гроші й KPI?
4. Чи не втомлює стиль після довгої сесії?
5. Чи виглядає продукт дорожче за типовий CRM?
6. Чи є характер COS?
7. Чи працює DataGrid?
8. Чи працюють forms?
9. Чи працює mobile?
10. Чи зберігається accessibility?
11. Чи не залежить UI від красивого фонового зображення?
12. Чи можна перенести стиль на всі Domains без окремого redesign?

## Правило Glass

Для Glass діє окремий принцип:

> glass for structure, opacity for information.

Navigation, shell, panels, cards, overlays і dashboard groups можуть бути виразно скляними.

Dense data, input controls і critical financial information мають отримувати достатню opacity/contrast для стабільного читання.

## Що не є результатом PHASE 2

PHASE 2 не:

- робить Origin production default;
- створює user preference persistence;
- затверджує фінальну font family;
- переносить стиль на всі Domains;
- видаляє Light або Dark;
- визначає переможця автоматично.

## Критерії завершення PHASE 2

PHASE 2 завершена, коли:

- шість кандидатів перемикаються на живому canonical UI;
- Origin A/B/C мають відчутно різний character;
- Glass є повноцінним UI candidate, а не одним translucent card;
- switcher доступний у `/dev/ui` і `/dev/workspace`;
- candidate CSS не містить Domain logic;
- немає persistence або нового frontend runtime;
- CI перевіряє Style Lab architecture;
- production theme contracts не зламані.

Після цього можна перейти до візуального відбору і PHASE 3 — canonical component visual pass.
