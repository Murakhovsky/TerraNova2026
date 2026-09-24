# WEB V0.17 — Workspace Canonicalization

WEB V0.17 переводить канонічну систему компонентів WEB V0.16 з контрольних прикладів у реальні робочі поверхні COS.

Мета версії не створювати нові UI-примітиви, а прибрати паралельні локальні реалізації однакових патернів і зробити канонічні компоненти типовим способом побудови Workspace.

## Принцип

```text
існуючий бізнес-сценарій
→ зберегти controller / domain behavior
→ замінити локальну presentation geometry
→ canonical COS component
→ перевірити responsive та regression gates
```

Міграція не повинна змінювати бізнес-логіку, URL-контракти або доменну відповідальність.

## Хвиля 1

### Property Submissions

`app/Interfaces/Web/View/property/submissions.phtml`

Заміни:
- legacy page hero → `PageHeader`;
- legacy empty/error blocks → `State`;
- `tn-listing-table` → `DataTable`;
- локальний status pill → semantic `Status` через `DataTable`;
- таблиця на mobile переходить у record cards через канонічний responsive contract.

Статуси модерації отримали семантичні tones: neutral, info, warning, positive, danger.

### Analytics

`app/Interfaces/Web/View/admin/analytics.phtml`

Заміни:
- legacy page hero → `PageHeader`;
- локальний period form → `FilterBar`;
- `tn-admin-metrics` → canonical `KPI Card`;
- legacy analytics cards → `Panel`;
- дві HTML-таблиці → `DataTable`;
- unavailable report → `State`.

Аналітика тепер використовує ті самі базові UX-контракти, що Sales і Property, замість окремої dashboard-мови.

### Diagnostic Report

`app/Interfaces/Web/View/diagnostic_report/show.phtml`

Заміни:
- legacy report header → `PageHeader`;
- `tn-card-grid / tn-card` summary → canonical `KPI Card`;
- секції звіту → `Panel`;
- measured outcomes → `Panel`.

Структура даних і генерація діагностичного звіту не змінюються.

## Хвиля 2

### COS Control Center

`app/Interfaces/Web/View/cos/index.phtml`

Control Center переведений із власної паралельної admin-мови на канонічний Workspace shell:
- `tn-listing-hero` → `PageHeader`;
- `tn-cos-metrics` → `KPI Card`;
- `tn-admin-tabs` → canonical `Tabs`;
- `tn-admin-panel` → `Panel`;
- `tn-cos-status-*` → semantic `Status`;
- primary/secondary execution controls → canonical buttons;
- action/page status використовує Calm Technical alert geometry.

Domain-specific content не штучно уніфікується. JSON details, decision cards, approval cards та audit list лишаються COS feature-patterns усередині канонічного shell. Це важливе правило: компонентна система стандартизує повторювану UX-мову, а не стирає доменну специфіку.

Щільні таблиці Control Center поки зберігають спеціалізовану markup-структуру, оскільки містять `details`, execution forms і approval anchors, які поточний `DataTable` contract не повинен симулювати сирим HTML.

### Client Case

WEB V0.17 спочатку перевів Client Case PHTML на canonical server components як проміжний compatibility етап.

Wave 13 Phase 4 завершив цей шлях повністю:

- `/client-case/inbox` → Symfony/Twig Operational Queue;
- `/client-case` → Symfony/Twig Collection;
- `/client-case/show/{id}` → Symfony/Twig Entity Workspace;
- read composition проходить через Application Queries і typed presentation ViewModels;
- `sales.client_case` зареєстрований як окремий Workspace context поверх Sales-owned entity `sales.deal`;
- усі Client Case POST flows централізовані у `ClientCaseMutationController`;
- `ClientCasePageController`, три Client Case PHTML views, `clients-workspace` Vite entrypoint та legacy Clients CSS видалені;
- canonical domain CSS лишає тільки спеціалізовану funnel geometry й використовує COS tokens.

Client Case після Wave 13 більше не має compatibility bridge. Routes, Sales write ownership, CSRF, return_url та mutation field contracts збережені.


## KPI tone contract

WEB V0.17 закриває API `KPI Card` до п'яти tones:

```text
neutral
brand
positive
warning
danger
```

`brand` використовує cobalt accent із COS Design Tokens. Довільні CSS-tone назви більше не є частиною контракту компонента.

## Regression policy

Для екранів, завершених у хвилі 1, заборонено повертати legacy presentation primitives:

```text
Property Submissions:
  tn-page-hero
  tn-listing-table
  tn-empty-state
  tn-status-pill

Analytics:
  tn-page-hero
  tn-admin-metrics
  tn-admin-card
  tn-listing-table
  tn-table-wrap

Diagnostic Report:
  tn-page-header
  tn-card-grid
  tn-card
```

Для COS Control Center у хвилі 2 заборонені legacy shell primitives:

```text
tn-listing-hero
tn-cos-metrics
tn-admin-tabs
tn-admin-panel
tn-cos-status--*
```

Для Client Case gate тепер фіксує:
- canonical Twig composition для Operational Queue, Collection та Entity Workspace;
- відсутність усіх трьох production PHTML views;
- відсутність `ClientCasePageController` і dedicated `clients-workspace` bundle;
- збереження server-first mutation contracts;
- відсутність `tn-*`, inline visual CSS та локального browser runtime у завершених Twig surfaces.

Architecture gate перевіряє не лише наявність canonical components, а й відсутність старих патернів у завершених частинах міграції.

## Definition of Done

Хвиля міграції вважається завершеною, коли:
- бізнес-дані та routes не змінені;
- view використовує канонічні компоненти для заголовка, станів, KPI, таблиць і panels там, де вони застосовні;
- domain-specific pattern лишається локальним лише там, де canonical primitive справді не покриває сценарій;
- legacy primitive не дублює канонічний контракт;
- PHTML проходить syntax validation;
- production Vite build проходить;
- WEB V0.16 та WEB V0.15 gates лишаються зеленими;
- окремий WEB V0.17 gate захищає мігровані surfaces від регресії;
- зміни проходять live AWS dev deploy без зміни runtime/business behavior.

## Завершення WEB V0.17

PHASE 9–12 закрили production adoption debt, який лишався після первинної Workspace Canonicalization:

- Sales production surfaces;
- Property route/view graph;
- Users / Content / Spatial administration;
- Client Case `index`, `inbox`, `show`.

WEB V0.17 більше не має окремого compatibility-bridge боргу. Подальші UI зміни мають бути новими product/design waves, а не продовженням старої міграції Phalcon-era presentation geometry.
