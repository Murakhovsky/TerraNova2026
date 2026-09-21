---
title: Канонічна мова Entity та Business UX COS
description: Контракт PHASE 7 для сутностей, workflow, ownership, actions, relations, activity та фінансових патернів у COS.
status: active
updated: 2026-09-21
kind: architecture
---

# Канонічна мова Entity та Business UX COS — PHASE 7

## Мета

PHASE 7 переводить COS від generic UI primitives до повторно використовуваної операційної мови бізнесу.

Domain володіє даними, правилами та workflow. Web Experience Platform володіє тим, як identity, state, ownership, next action, money, relations та activity подаються користувачу.

```text
Domain state
→ ViewModel / UIAction
→ canonical business pattern
→ Twig
→ COS tokens
```

Компоненти PHASE 7 не читають Doctrine, API, Domain repositories чи session context.

## Сутність як основна одиниця роботи

Канонічний entity workspace будується з:

- `CosEntityHeader`;
- `CosEntitySummary`;
- `CosEntityCard`;
- `CosEntityListItem`;
- `CosRelations`;
- `CosTimeline`;
- `CosActivityFeed`.

`EntityHeader` не є dashboard card. Він показує identity, title, semantic status, ключові metadata та governed actions.

`EntitySummary` містить короткі facts/KPI, які потрібні для рішення, але не дублює великий dashboard.

`EntityCard` і `EntityListItem` є двома density-рівнями тієї самої entity language.

## Дії та фільтрація

Канонічні action surfaces:

- `CosActionBar`;
- `CosBulkActionBar`;
- `CosFilterBar`.

`ActionBar` визначає visual hierarchy дій, але не виконує authorization. Policy та Application layer залишаються authoritative.

На entity surface має бути один очевидний primary action. Secondary та danger actions не конкурують із ним декоративною вагою.

## Workflow, ownership і next action

`CosStage` описує позицію у workflow, а `CosStatus` описує semantic state/result.

Це різні поняття:

```text
Stage: Proposal
Status: At risk
```

`CosOwner` подає відповідальну особу або роль.

`CosNextAction` показує операційний наступний крок і due context. COS має відповідати на питання “що робити далі?” без пошуку по вкладках.

## Relations та activity

`CosRelations` показує typed relations між бізнес-сутностями.

`CosTimeline` є chronology-oriented view: що відбулося і коли.

`CosActivityFeed` є actor-oriented view: хто або що виконало дію. Source може відрізняти human, agent, system та integration, але presentation не вигадує provenance самостійно.

## Фінансова мова

PHASE 7 додає:

- `CosMoneyMetric`;
- `CosTrendMetric`.

Вони використовують PHASE 3 numeric contract:

```css
font-variant-numeric: tabular-nums lining-nums;
```

`MoneyMetric` може показувати value, delta, target і progress.

`TrendMetric` завжди має textual delta/direction context. Колір не є єдиним носієм значення.

Повний Financial UI mini-system залишається окремою PHASE 12, але ці patterns створюють спільний cross-Domain baseline.

## Композиція

Типовий entity workspace:

```text
EntityHeader
EntitySummary
Tabs
NextAction / Owner / Stage
Panels
Relations
Timeline / ActivityFeed
ActionBar
```

Типовий operational list:

```text
Page/Workspace Header
FilterBar
DataGrid / EntityListItem
BulkActionBar
Drawer
```

Це patterns, а не готові Domain screens.

## Responsive contract

На mobile:

- `EntityHeader` переходить у одну колонку;
- actions можуть займати повну ширину;
- `EntitySummary` reflow-иться, а не стискається;
- `EntityListItem` переносить secondary meta;
- `FilterBar` переходить у vertical composition;
- `NextAction` зберігає title і due context;
- `Timeline` переносить time та content без горизонтального scroll;
- sticky `ActionBar` поважає safe-area.

## Доступність

Обов'язкові правила:

- action groups мають accessible label;
- Stage та Status завжди містять текст;
- relation list є semantic list;
- Timeline/ActivityFeed використовують ordered lists;
- linked entity surfaces залишаються keyboard-focusable;
- money/trend semantics не залежать лише від кольору;
- responsive touch targets використовують canonical control sizing.

## Межі відповідальності

PHASE 7 не створює:

- Sales-specific `DealCard`;
- Property-specific `PropertyActionBar`;
- Finance-specific локальну metric system;
- Domain-specific buttons, inputs або status badges.

Domain додає content і visualization там, де це справді business-specific, але базова anatomy лишається shared.

## Критерії завершення PHASE 7

PHASE 7 завершена, коли:

- усі canonical business patterns зареєстровані у UI Catalog;
- `/dev/ui` має reference entity surface;
- EntityHeader розділяє identity, status, metadata та actions;
- Stage відділений від Status;
- Owner і NextAction мають canonical presentation;
- Relations, Timeline та ActivityFeed мають stable semantic markup;
- MoneyMetric і TrendMetric використовують numeric contract;
- FilterBar, ActionBar та BulkActionBar не містять business authorization;
- mobile reflow визначений у shared CSS;
- architecture gate блокує Domain/data dependencies у компонентах;
- Design System runtime smoke рендерить PHASE 7.
