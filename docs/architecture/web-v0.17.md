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

## Що навмисно не входить у хвилю 1

Великі legacy-heavy поверхні не переписуються одним ризиковим комітом. До хвилі 2 переходять:
- Client Case list / inbox / entity view;
- COS Control Center;
- решта Property operational tables;
- Sales Administration screens, які ще мають локальні `sales-admin-*` primitives;
- Content / Spatial / Users administration.

Причина проста: канонізація повинна бути механічною presentation-міграцією. Якщо view одночасно містить складні форми, workflow logic і великий локальний JS contract, її треба переносити окремою контрольованою хвилею, а не масовою заміною класів.

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

Architecture gate перевіряє не лише наявність canonical components, а й відсутність цих старих патернів у мігрованих views.

## Definition of Done

Хвиля міграції вважається завершеною, коли:
- бізнес-дані та routes не змінені;
- view використовує канонічні компоненти для заголовка, станів, KPI, таблиць і panels там, де вони застосовні;
- legacy primitive не дублює канонічний контракт;
- PHTML проходить syntax validation;
- production Vite build проходить;
- WEB V0.16 та WEB V0.15 gates лишаються зеленими;
- окремий WEB V0.17 gate захищає мігровані screens від регресії.

Наступна хвиля 2 має переносити складні Workspace surfaces по одному доменному кластеру, починаючи з Client Case та COS Control Center.
