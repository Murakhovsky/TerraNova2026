# WEB V0.14 — Frontend Standard Stack

WEB V0.14 фіксує стандартний набір frontend-інструментів COS. Це не перехід до SPA і не заміна поточної моделі `Public | Portal | Workspace`.

Основний принцип залишається **server-first, progressively enhanced**:

```text
Phalcon / PHTML
    ↓
COS Design System
    ↓
Bootstrap / HTMX mechanics за потреби
    ↓
feature-specific libraries лише у відповідному feature
```

Business truth, authorization, lifecycle та application use cases залишаються на сервері. Браузер відповідає за presentation та interaction.

## Базовий стек

| Призначення | Стандарт |
|---|---|
| Server-rendered UI | Phalcon + PHTML |
| Asset pipeline | Vite |
| Layout і browser UI mechanics | Bootstrap 5.3.8 |
| Іконки | Bootstrap Icons 1.13.1 |
| Partial server interactions | HTMX 2.0.10 |
| JSON/API interactions | native `fetch()` |
| Невелика browser behaviour | native JavaScript |
| Складні таблиці | Tabulator 6.5.2 |
| Charts | Chart.js 4.5.1 |
| Drag & drop | SortableJS 1.15.7 |
| Date/date-range UI | Flatpickr 4.6.13 |
| Calendar UI | FullCalendar 7.1.0 + Temporal polyfill |
| Architecture graphs | поточний Cytoscape adapter |
| Complex reactive islands | Vue допускається пізніше лише локально |

Версії основного standard stack pinned у `package.json`. Оновлення бібліотеки є свідомою frontend dependency migration, а не випадковим наслідком `npm install`.

## Bootstrap не є дизайн-системою COS

Bootstrap використовується як перевірена browser/UI mechanics layer:

- modal;
- offcanvas;
- dropdown;
- collapse;
- tabs;
- tooltip/popover;
- forms;
- grid/utilities;
- базова accessibility поведінка компонентів.

Канонічною візуальною мовою залишаються `tn-*` tokens, components і patterns.

CSS cascade ownership:

```text
tn-vendor
  ↓
tn-reset
  ↓
tn-tokens
  ↓
tn-foundation
  ↓
tn-components
  ↓
tn-patterns
  ↓
tn-production
  ↓
tn-layouts
  ↓
tn-features
```

Bootstrap CSS і Bootstrap Icons завантажуються в `tn-vendor`. `bootstrap-bridge.css` працює в `tn-components` і прив'язує Bootstrap presentation до COS tokens.

Таким чином Bootstrap може дати поведінку `.modal`, `.offcanvas` або `.dropdown`, але не отримує право визначати бренд COS.

## Opt-in runtime

Bootstrap + HTMX не додаються автоматично в базові `public-surface`, `portal-cabinet` або `terranova-interface` bundles.

Окремий Vite entrypoint:

```text
cos-ui-runtime
```

підключається сторінкою або feature лише тоді, коли їй потрібні ці можливості.

Це зберігає WEB V0.12 performance budgets і не змушує просту сторінку оплачувати код modal, AJAX або icon font, яким вона не користується.

## HTMX contract

HTMX є canonical механізмом для server interactions, коли відповіддю природно є HTML fragment:

```text
Browser action
    ↓
HTTP request
    ↓
Web Controller / Application Use Case
    ↓
PHTML fragment
    ↓
HTMX swap
```

Типові сценарії:

- drawer/modal content;
- filtering;
- pagination;
- search;
- inline server actions;
- partial refresh;
- activity feed;
- lightweight workflow interactions.

`frontend/core/cos-ui-runtime.js` централізує interaction contract:

- mutation requests отримують CSRF token із найближчого `data-csrf`, hidden `csrf_token` або meta fallback;
- request source/form отримує `aria-busy`;
- після request використовується той самий submit-state reset, що й для ordinary forms;
- після swap повторно ініціалізуються Bootstrap tooltip/popover behaviors;
- transport failures проєктуються у `cos:request-error` без показу raw server exception body.

HTMX не визначає permissions, domain state або workflow transitions.

## Native fetch

`fetch()` залишається правильним вибором для API interactions, де payload і response є JSON, а не HTML projection.

Не потрібно переписувати існуючі стабільні JSON API clients на HTMX лише заради одноманітності. Вибір залежить від contract:

```text
HTML fragment → HTMX
JSON contract → fetch()
```

## Feature libraries

Tabulator, Chart.js, SortableJS, Flatpickr і FullCalendar встановлені як approved dependencies, але не імпортуються в `cos-ui-runtime`.

Вони доступні через lazy adapters:

```text
frontend/core/libraries/
├── data-grid.js
├── charts.js
├── sortable.js
├── date-picker.js
└── calendar.js
```

Тому Vite створює окремі chunks лише коли конкретний feature викликає loader.

Правило використання:

- проста HTML table → звичайний server-rendered HTML;
- operational data grid → Tabulator;
- просте date input → native input, якщо цього достатньо;
- складний date/range picker → Flatpickr;
- chart → Chart.js;
- drag/drop workflow або Kanban → SortableJS;
- повноцінний schedule/calendar → FullCalendar.

Бібліотека не додається до feature лише тому, що вона вже є в dependencies.

## jQuery

jQuery не входить до global standard stack.

Він може бути доданий локально в майбутньому лише як compatibility dependency конкретного third-party plugin, якщо цей plugin справді виправдовує залежність. Такий випадок не дає jQuery права стати глобальним browser API COS.

## React / Angular / Vue

React і Angular не потрібні для поточної server-rendered архітектури та не входять до standard stack.

Vue також не встановлюється зараз. Він зарезервований як можливий інструмент для **complex interactive islands**, наприклад:

- workflow/process designer;
- складний visual schema editor;
- dashboard builder;
- rich AI workspace;
- інший екран, де browser state уже сам по собі є значною application concern.

Навіть у такому випадку Vue island має володіти конкретним контейнером/feature, а не автоматично перетворювати весь COS Web на SPA.

## Vite ownership

Усі standard-stack assets збираються Vite. CDN runtime dependencies для Bootstrap/HTMX та approved libraries не допускаються.

Новий entrypoint:

```text
frontend/entrypoints/cos-ui-runtime.js
```

є частиною `frontend_assets.php` gate і production manifest.

## Migration policy

WEB V0.14 не переписує всі існуючі екрани одночасно.

Міграція відбувається при розвитку feature:

1. existing stable UI не переписується без продуктової причини;
2. нові generic interactions використовують standard stack;
3. повторюваний власний browser code поступово замінюється на Bootstrap/HTMX або approved library;
4. `tn-*` component API залишається канонічним presentation vocabulary;
5. feature-specific dependencies залишаються feature-local і lazy.

## Regression gate

`tests/architecture/web_v014_standard_stack.php` перевіряє:

1. pinned standard dependencies;
2. відсутність global jQuery/React/Angular/Vue/Alpine dependencies;
3. синхронність `package-lock.json`;
4. `tn-vendor` cascade boundary;
5. Bootstrap CSS/Icon ownership через Vite;
6. Bootstrap bridge до COS tokens;
7. HTMX CSRF/busy/error/swap contract;
8. shared form submit-state reset;
9. lazy ownership heavy feature libraries;
10. незалежність базових Public/Portal/Workspace bundles від opt-in runtime;
11. наявність `cos-ui-runtime` у Vite та shared asset gate.

## Definition of Done

WEB V0.14 закритий, коли:

- npm dependencies і lockfile синхронні;
- `npm ci` та `npm run build` проходять;
- `cos-ui-runtime` присутній у Vite manifest;
- Bootstrap і Bootstrap Icons знаходяться нижче COS Design System у cascade;
- HTMX має один shared interaction adapter;
- feature libraries доступні через lazy adapters і не входять у base bundles;
- WEB V0.12 production budgets не регресують;
- dedicated V0.14 gate та shared frontend asset gates проходять у CI.
