---
title: "Закриття залишкових control surfaces"
description: "PHASE 13 прибирає останні raw read-only presentation islands після закриття domain-level production adoption phases."
status: active
updated: 2026-09-22
kind: architecture
---

# Закриття залишкових control surfaces

PHASE 13 працює не з окремим доменом, а з залишковими read-only presentation islands, які пережили PHASE 9–12.

Принцип простий:

- read-only tabular information переходить на canonical DataTable;
- editable/transactional grids не маскуються під read-only table;
- specialized control surfaces можуть залишатися domain-specific, якщо містять first-class forms, JSON inspection, approvals або execution actions.

## Хвиля 1

### Вхідні зв’язки Client Case

`client_case/show.phtml`

Остання raw read-only таблиця у Client Case Workspace переведена на canonical DataTable:

- inbound requests нормалізуються у `$inboundRows`;
- intent, deal type, message і created-at залишаються scalar cells;
- property relation використовує primary/secondary cell;
- `_href` зберігає deep-link на `property/show/{slug}`, якщо заявка прив’язана до об’єкта;
- empty state переходить у стандартний DataTable empty contract;
- responsive mode — `cards`.

Ця зміна не торкається Client Case mutations, AI actions, timeline, property-match forms або presentation sharing.

## Хвиля 2

### Таблиці COS Control Center

`cos/index.phtml`

Read-only runtime sections переведено на canonical Panel + DataTable:

- Events;
- Rules;
- Agents;
- Policies;
- Integrations;
- Results.

Для JSON/config/error payload canonical DataTable отримав safe `details` cell:

- summary рендериться як текст;
- content рендериться всередині escaped `<pre>`;
- довільний HTML не приймається.

Це дозволяє показувати payload, rule configuration та result/error output без локального table markup і без unsafe rendering.

`Proposed Actions` свідомо лишається operational raw grid, тому що рядки містять:

- POST Execute form;
- CSRF token;
- approval anchor;
- status/risk context;
- JSON parameters.

Це не read-only dataset. Його наступний canonical pattern має бути operational action grid із first-class mutation cells, а не розширення DataTable до універсального form renderer.

## Хвиля 3

### Черга рішень Company Home

`admin/index.phtml`

Остання проста read-only таблиця Company Home переведена на canonical DataTable:

- pending approvals та open actions нормалізуються у `$decisionRows`;
- status і risk використовують semantic status cells;
- responsive mode — `cards`;
- link на COS Control Center лишається у canonical panel header;
- мутацій у цій черзі немає, тому локальний table markup більше не потрібний.

### Фінальний аудит таблиць

PHASE 13 gate рекурсивно сканує `app/Interfaces/Web/View/**/*.phtml`.

Будь-який PHTML із `<table>` має бути або canonical DataTable renderer, або явно класифікованим винятком. На момент closure whitelist складається лише з:

1. `components/ui/data_table.phtml` — canonical renderer;
2. `admin/users.phtml` — editable identity grid із row-level forms;
3. `client_case/index.phtml` — operational quick-update grid;
4. `methodology_studio/index.phtml` — JS-driven methodology editor grid;
5. `property/pdf.phtml` — service-level print renderer.

Після PHASE 15 перший post-freeze cleanup прибрав `cos/index.phtml` із whitelist: Proposed Actions переведено на canonical `OperationalGrid` із first-class mutation actions.

Якщо новий raw table з’явиться в іншому production view, PHASE 13 gate падає. Якщо один із винятків перестає містити table, gate також падає, змушуючи прибрати застарілий whitelist entry. Так винятки не перетворюються на вічні археологічні пам’ятки.

## Наступні хвилі

- Wave 2: COS Control Center read-only runtime tables — виконано;
- Wave 3: residual audit та classification винятків — виконано.

## Винятки

### Редагована таблиця користувачів

`admin/users.phtml` містить inline user mutations і form ownership на рівні рядка. Це не read-only DataTable і лишається explicit editable-grid boundary.

### Операційні дії COS

У COS Control Center секції з execute/approval forms можуть залишатися specialized operational grids навіть після канонізації read-only runtime tables.

## Критерії завершення

- Client Case Show не містить raw `tn-listing-table` для inbound relations;
- property deep links збережені;
- PHASE 12 workflow/mutation guards лишаються intact;
- шість read-only runtime tables COS використовують canonical DataTable;
- Proposed Actions після post-freeze cleanup використовує canonical OperationalGrid з execute/approval forms;
- Company Home decision queue використовує canonical DataTable;
- production PHTML raw-table whitelist обмежений шістьма класифікованими surfaces;
- PHASE 13 architecture gate запускається у CI;
- винятки класифіковані явно, а не залишені випадково.
