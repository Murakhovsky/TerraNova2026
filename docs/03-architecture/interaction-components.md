---
title: Компоненти взаємодії
description: Канонічні Modal, Drawer, Dropdown, Tabs, Popover, Tooltip, Confirm і Toast для COS Experience Platform.
status: active
updated: 2026-09-21
kind: architecture
---

# Компоненти взаємодії

Wave 12.7 стандартизує базові browser interactions поверх Twig Components і малих Stimulus controllers.

```text
Twig Component
    ↓
semantic HTML
    ↓
Stimulus behavior
    ↓
COS tokens / responsive rules
```

Компонент не отримує business logic, не викликає внутрішній REST API і не зберігає application state у браузері.

## Канонічний набір

- `CosModal` — фокусована overlay-взаємодія на native `<dialog>`;
- `CosDrawer` — inspect/edit поверх поточного контексту, на mobile переходить у full-width;
- `CosDropdown` — компактне меню дій або навігації;
- `CosTabs` — tablist із keyboard navigation;
- `CosPopover` — contextual server-rendered content;
- `CosTooltip` — коротка допоміжна підказка для hover/focus;
- `CosConfirm` — presentation confirmation, включно зі step-up;
- `CosToast` — коротке live-region повідомлення.

## Modal, Drawer і Confirm

Використовують native `<dialog>` і спільний `dialog` controller.

Базові правила:

- `Escape` закриває dismissible dialog;
- backdrop click працює тільки для dismissible dialog;
- після закриття focus повертається на trigger;
- Drawer на вузькому екрані займає повну ширину;
- Confirm не виконує business action самостійно;
- server fragment для Modal/Drawer передається як вкладений `<turbo-frame>`, а Stimulus відповідає лише за browser behavior.

`CosConfirm` диспатчить browser event `cos:confirm`. Наступний presentation/application adapter може перетворити підтверджену дію на Application Command або ActionProposal. Backend policy та authorization залишаються авторитетними.

Для critical action step-up вимагає введення `CONFIRM`; цей UX guard доповнює, а не замінює backend authorization.

## Випадне меню

Dropdown володіє лише presentation state:

```text
closed ↔ open
```

Воно закривається при outside click або `Escape`, повертає focus на trigger і підтримує `ArrowUp`, `ArrowDown`, `Home`, `End` для menu items. Menu content передається як Twig inner content, тому Domain не кодує HTML у props.

## Вкладки

Tabs підтримують:

- `ArrowLeft` / `ArrowRight`;
- `Home` / `End`;
- `aria-selected`;
- disabled tabs;
- event `cos:tab-change`.

Panels лишаються server-rendered content і маркуються `data-tabs-target="panel"` + `data-tab-key`.

## Popover і Tooltip

Popover відкривається явною дією, переводить focus у panel і закривається через outside click / `Escape`; при `Escape` focus повертається на trigger.

Tooltip доступний через hover і keyboard focus. Значення ніколи не повинно бути доступне тільки через hover.

## Короткі сповіщення

Toast використовує semantic live regions:

- info/positive → `role=status`, polite;
- warning/danger → `role=alert`, assertive.

Автозакриття є presentation behavior. Toast не є джерелом стану операції і не замінює Activity Center або audit trail.

## Зв’язок із Unified UIAction

Wave 12.6 і 12.7 мають чітку межу:

```text
UIAction
    ↓
placement / danger / confirmation contract
    ↓
Interaction Components
    ↓
user interaction
    ↓
Application boundary
```

`UIActionConfirmation` задає semantics підтвердження. `CosConfirm` є presentation surface для цих semantics.

## Компонентний контракт

Кожен із восьми компонентів належить Experience Platform, має статус `stable` у межах Wave 12 і не знає про Domains.

| Компонент | Основні props | Варіанти / стани | Slot | Browser dependency |
| --- | --- | --- | --- | --- |
| `CosModal` | `id`, `title`, `size`, `dismissible` | sm/md/lg/xl, open/closed | content | dialog + Stimulus |
| `CosDrawer` | `id`, `title`, `side`, `dismissible` | start/end, open/closed | content | dialog + Stimulus |
| `CosDropdown` | `id`, `label`, `align` | start/end, open/closed | menu content | Stimulus |
| `CosTabs` | `id`, `items`, `active` | active/disabled | panels | Stimulus |
| `CosPopover` | `id`, `placement`, `title` | top/end/bottom/start | content | Stimulus |
| `CosTooltip` | `id`, `placement`, `text` | top/end/bottom/start | — | Stimulus |
| `CosConfirm` | `id`, `tone`, `stepUp` | primary/warning/danger | content | dialog + Stimulus |
| `CosToast` | `id`, `tone`, `duration` | info/positive/warning/danger | — | Stimulus |

## Адаптивність

- Modal зберігає viewport margins;
- Drawer стає full-width;
- Dropdown обмежує ширину viewport;
- Tabs дозволяють horizontal overflow;
- Toast враховує safe-area inset.

## Межі

Stimulus controllers Interaction layer не можуть містити:

- `fetch()` або axios;
- `/api/*` URLs;
- `localStorage` / `sessionStorage` application state;
- Domain/Application imports.

Server interactions підключаються наступними platform layers через Turbo/Live/Application adapters.

## Перевірка

`/dev/ui` містить живі приклади всіх восьми компонентів.

CI перевіряє:

- Twig component presence;
- semantic roles/ARIA;
- keyboard behavior;
- presentation boundaries;
- token-only CSS;
- mobile/reduced-motion rules;
- реальний Twig render через `cos:web:interactions:smoke`.
