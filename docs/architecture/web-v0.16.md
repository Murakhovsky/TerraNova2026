# WEB V0.16 — Канонічна система UI-компонентів COS

WEB V0.16 переводить `Calm Technical` з візуальної мови у повторно використовувані UX-контракти. Компонент у COS є не окремим шматком HTML/CSS, а узгодженим набором семантики, вигляду, поведінки, станів, адаптивності та серверного API.

## Принцип

```text
COS workflow
→ UX pattern
→ canonical component
→ PHTML contract
→ COS tokens / CSS
→ progressive enhancement only where needed
```

Bootstrap, HTMX та інші бібліотеки лишаються технічними примітивами. Канонічні компоненти визначають продуктову мову COS.

## Канонічний набір V0.16

### 1. PageHeader

`components/ui/page_header.phtml`

Призначення: заголовок звичайної сторінки або робочого розділу.

Контракт:
- `eyebrow`;
- `title`;
- `description`;
- `meta`;
- `actions`.

Дії завжди проходять через канонічний `ActionBar`.

### 2. EntityHeader

`components/ui/entity_header.phtml`

Призначення: ідентичність конкретної сутності, наприклад Deal, Client, Property, Agent або Process.

Контракт:
- `eyebrow`;
- `identity`;
- `title`;
- `subtitle`;
- semantic `status`;
- список `meta`;
- `actions`.

`PageHeader` та `EntityHeader` не взаємозамінні. Сторінка сутності має показувати її identity, status і ключові metadata без перетворення заголовка на dashboard-card.

### 3. Panel

`components/ui/panel.phtml`

Призначення: структурно відокремлений блок інформації або операцій.

Варіанти:
- `default`;
- `compact`;
- `flush`.

Panel використовує border/contrast як основний спосіб структурування. Shadow не є декоративним ефектом і лишається мінімальним.

### 4. DataTable

`components/ui/data_table.phtml`

Призначення: серверний список операційних даних.

Контракт:
- `columns`;
- `rows`;
- `responsive`;
- `emptyMessage`.

Комірка може бути:
- простим текстом;
- primary/secondary value;
- semantic `Status`;
- `Stage`.

На вузькому екрані режим `cards` перетворює рядки таблиці на читабельні record blocks із `data-label`, а не просто стискає desktop-таблицю до непридатного стану.

Для складних великих таблиць Tabulator лишається feature-level інструментом. `DataTable` є базовим server-rendered контрактом.

### 5. FilterBar

`components/ui/filter_bar.phtml`

Призначення: пошук і фільтрація списків.

Підтримує базові `text`, `search`, `date`, `select`. Складні специфічні фільтри будуються композицією, а не розширенням компонента десятками випадкових параметрів.

### 6. ActionBar

`components/ui/action_bar.phtml`

Призначення: єдине місце для primary/secondary/danger дій.

Варіанти вирівнювання:
- `start`;
- `between`;
- `end`.

Може бути `sticky` для операційних і mobile-сценаріїв. На сторінці не повинно з'являтися кілька конкуруючих primary actions без явної UX-причини.

### 7. Drawer

`components/ui/drawer.phtml`

Призначення: inspect/edit/action без втрати контексту списку.

Drawer реалізований на native `<dialog>`:
- не потребує SPA state manager;
- закривається Escape;
- має явну close-action;
- адаптується до full-width на mobile;
- підходить для HTMX fragment loading у наступних вертикалях.

Канонічний сценарій:

```text
list → select entity → drawer → inspect/edit/action → return to list context
```

### 8. Status / Stage

`components/ui/status_badge.phtml`
`components/ui/stage.phtml`

`Status` описує стан результату або системи і використовує тільки semantic tones:
- neutral;
- positive;
- warning;
- danger;
- info.

`Stage` описує позицію у workflow. Він може використовувати brand accent, бо accent тут означає поточний етап, а не success.

Колір ніколи не є єдиним носієм значення: компонент завжди має текстову мітку.

## Правила композиції

Канонічна entity-сторінка збирається приблизно так:

```text
EntityHeader
Tabs
KPI / summary
Panel
Panel
Activity / Timeline
ActionBar
Drawer (за потреби)
```

Список:

```text
PageHeader
FilterBar
DataTable
ActionBar / bulk actions
Drawer
```

Це шаблони, а не жорсткі сторінки. Domain визначає зміст, UI system визначає спосіб його подання.

## Адаптивність

Кожен canonical component повинен визначати не тільки desktop-size, а зміну поведінки:
- `EntityHeader`: дві колонки → одна;
- `DataTable`: table → record cards;
- `Drawer`: side panel → full-width;
- `ActionBar`: може стати sticky над mobile navigation;
- `FilterBar`: поля й actions переходять у повну ширину.

Принцип: responsive означає reflow і reprioritization, а не механічне зменшення.

## Accessibility

Базовий орієнтир: WCAG 2.2 AA.

Обов'язково:
- semantic HTML;
- keyboard focus;
- native dialog semantics для Drawer;
- `aria-label` для action groups і close controls;
- status/stage мають текст, а не тільки колір;
- touch targets не стискаються до desktop-micro-controls на mobile.

## Server-first

Canonical system не змінює архітектуру COS:

```text
PHTML → HTML
HTMX → partial HTML
fetch() → JSON API
Vanilla JS → локальна browser behavior
```

Компонент не повинен вимагати Vue/React лише для базової інтеракції. Complex reactive island залишається окремим винятком.

## Реальні міграції V0.16

Першими контрольними сценаріями стали:
- `Sales Intelligence Agents` → canonical `DataTable` + semantic status;
- `Sales Intelligence Agent` → canonical `EntityHeader`.

Це перевіряє систему на реальних operational data, а не на декоративному showcase.

## Definition of Done

Канонічний компонент вважається завершеним, якщо має:

```text
Purpose
+ Anatomy
+ Stable PHTML API
+ Variants
+ States
+ Responsive behavior
+ Keyboard/touch behavior
+ Accessibility semantics
+ COS token ownership
+ Real-domain usage
+ Architecture test
```

Локальні domain styles можуть розширювати компонент, але не повинні дублювати його базову геометрію, semantic colors або interaction contract.
