---
title: Канонічні stress screens COS
description: PHASE 8 visual stress-test surfaces для Company Home, DataGrid, entity workspace та COS Control/AI.
status: active
updated: 2026-09-21
kind: architecture
---

# Канонічні stress screens COS — PHASE 8

## Мета

PHASE 8 перевіряє visual language не на ізольованих компонентах, а на чотирьох щільних reference surfaces, визначених Visual Constitution:

1. Executive / Company Home;
2. dense operational DataGrid;
3. entity workspace;
4. COS Control / AI / operations surface.

Канонічний manager-only reference route:

`/dev/stress`

Surface є лабораторією, а не новим business Domain чи production dashboard.

## 1. Керівний екран (`Company Home`)

Перевіряє:

- scan speed;
- financial readability;
- KPI hierarchy;
- decision/attention priority;
- health/status density;
- action discoverability.

Ключове правило: Company Home показує company-level operating projection і не дублює повні Domain dashboards.

## 2. Щільний операційний `DataGrid`

Використовує canonical `CosDataGrid` без окремої grid system.

Перевіряє:

- search;
- filters;
- sorting;
- saved views;
- columns;
- bulk selection;
- row actions;
- pagination;
- horizontal pressure;
- mobile card fallback.

PHASE 8 не створює новий table engine.

## 3. Робочий простір сутності

Композиція:

```text
EntityHeader
EntitySummary
Stage
Owner
NextAction
Relations
Timeline
ActionBar
```

Перевіряє, чи PHASE 7 patterns працюють разом під реальною щільністю.

EntityHeader лишається identity surface, а не декоративною dashboard card.

## 4. Контроль COS, AI та операцій

Перевіряє одночасно:

- runtime health;
- system degradation;
- governed AI proposals;
- human/agent/system/integration provenance;
- warnings;
- operational activity.

Control surface не повинен перетворюватися на terminal UI. Технічна інформація підпорядковується hierarchy, semantic status та action governance.

## Shared rules

Stress screens повинні:

- використовувати тільки canonical COS tokens/components;
- не створювати Domain-specific primitives;
- не читати Domain repositories;
- не виконувати authorization;
- не змінювати business state;
- мати mobile reflow;
- залишатися manager-only;
- бути `noindex, nofollow`;
- не кешуватися публічно.

## Testing contract

PHASE 8 має:

- architecture gate;
- Symfony runtime render smoke;
- route registration check;
- auth redirect check;
- CSS responsive markers;
- reference markers для всіх чотирьох surfaces.

Wave 12.23 browser-quality pipeline залишається загальним browser/accessibility evidence layer.

## Критерії завершення

PHASE 8 завершена, коли:

- `/dev/stress` рендерить чотири canonical surfaces;
- Company Home витримує executive density;
- DataGrid не створює horizontal overflow на reference wrapper;
- entity workspace використовує PHASE 7 patterns як composition;
- control/AI surface розрізняє business, system та agent signals;
- mobile layout не є просто зменшеним desktop;
- runtime smoke зелений;
- architecture gate зелений.
