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

`frontend/features/clients/workspace.css`
`frontend/features/clients/workspace.js`

Client Case має складний operational UI: create/update forms, inbound triage, funnel, quick updates, matches, activities та AI actions. Повна механічна заміна view markup одним комітом створила б непотрібний regression risk.

Тому хвиля 2 вводить контрольований compatibility bridge:
- старий card-like hero візуально переходить до плоского структурного `PageHeader` pattern;
- metrics використовують геометрію canonical KPI;
- tabs переходять на line/navigation pattern canonical Tabs;
- panels отримують `Calm Technical` radius, border і restrained elevation;
- inputs/selects/textareas використовують canonical surface/focus geometry;
- generic form actions переходять із graphite на cobalt action signal;
- старий beige/gold form feedback прибраний;
- local positive-green brand accents прибрані з kicker/status presentation;
- duplicate submit-state JS видалений, Client Case покладається на спільний `initProductionUX` guard базового Workspace.

Цей bridge навмисно не оголошується фінальною server-component міграцією Client Case. Наступна контрольована хвиля повинна окремо перевести `index`, `inbox` та `show` на `PageHeader / EntityHeader / FilterBar / Panel / Status` без зміни workflow forms і funnel behavior.

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

Для Client Case compatibility bridge gate фіксує:
- відсутність старого beige/gold accent;
- cobalt focus/action signal;
- використання canonical COS tokens;
- відсутність дубльованого submit-state JS.

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

Після завершення PHASE 9–11 залишковий борг WEB V0.17 звужено до server-component міграції Client Case views (`index`, `inbox`, `show`) без зміни workflow forms, funnel та mutation behavior. Property operational surfaces, Sales Administration, Users, Content і Spatial уже переведені на canonical production contracts або закриті на фактичному Symfony route/view graph.
