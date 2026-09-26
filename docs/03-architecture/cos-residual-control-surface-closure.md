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

`symfony/templates/experience/client_case/show.html.twig`

Історично остання raw read-only таблиця у Client Case Workspace була переведена на canonical DataTable. Wave 13 Phase 4 після цього завершив повний Twig cutover:

- inbound relations тепер рендеряться responsive Entity Workspace cards;
- property deep-link на `property/show/{slug}` збережений;
- empty state використовує canonical `CosEmptyState`;
- raw table/PHTML dependency видалений разом із `client_case/show.phtml`.

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

Canonical table renderers:

1. `components/ui/data_table.phtml` — read-only DataTable;
2. `components/ui/operational_grid.phtml` — mutation-aware OperationalGrid.

Product-level raw-table exceptions після post-freeze cleanup:

- немає.

`property/pdf.phtml` лишається service-level print renderer, але більше не використовує HTML table як layout primitive. Print layout побудовано на Dompdf-safe float / inline-block blocks, тому PHASE 13 raw-table whitelist тепер складається лише з canonical renderers.

Після PHASE 15 перший post-freeze cleanup прибрав `cos/index.phtml` із product whitelist: Proposed Actions переведено на canonical `OperationalGrid` із first-class mutation actions.

Другий post-freeze cleanup прибрав `admin/users.phtml` із whitelist: row-level Identity mutations переведено на editable OperationalGrid з row form ownership, CSRF, typed field cells та submit action.

Третій post-freeze cleanup прибрав `client_case/index.phtml` із whitelist: quick-update workflow переведено на OperationalGrid із row form ownership, semantic Stage, editable workflow fields, CSRF/return-url та submit/deep-link actions.

Четвертий post-freeze cleanup прибрав `methodology_studio/index.phtml` із whitelist: JS-driven entity browser більше не використовує HTML table. `data-entities` лишився client-render mount, а rows переведені на responsive semantic CSS grid із збереженням `data-edit` delegation та editor workflow.

П’ятий post-freeze cleanup прибрав останній product-level exception `property/pdf.phtml` із raw-table whitelist. PDF template зберігається як service-level print renderer у `PropertyPresentationService`, але hero, facts, characteristics, partner conditions, gallery та group cards більше не будуються через `<table>`. Для Dompdf використано print-safe float / inline-block layout без зміни document variants або PDF ownership.

Якщо новий raw table з’явиться в іншому production view, PHASE 13 gate падає. Якщо один із винятків перестає містити table, gate також падає, змушуючи прибрати застарілий whitelist entry. Так винятки не перетворюються на вічні археологічні пам’ятки.

## Наступні хвилі

- Wave 2: COS Control Center read-only runtime tables — виконано;
- Wave 3: residual audit та classification винятків — виконано.

## Винятки

### Редагована таблиця користувачів

`admin/users.phtml` більше не є raw-table винятком. Inline user mutations перенесені на canonical OperationalGrid editable row form contract без зміни Identity routes або field semantics.

### Браузер сутностей Methodology Studio

`methodology_studio/index.phtml` більше не є raw-table винятком. Це лишається specialized JS-driven editor surface, але entity collection рендериться через semantic `role=table/row/columnheader/cell` CSS grid, а не через локальний HTML `<table>`.

### Операційні дії COS

У COS Control Center секції з execute/approval forms можуть залишатися specialized operational grids навіть після канонізації read-only runtime tables.

## Критерії завершення

- Client Case Show є canonical Twig Entity Workspace і не містить raw tables або `tn-*` presentation;
- property deep links збережені;
- PHASE 12 workflow/mutation guards лишаються intact;
- шість read-only runtime tables COS використовують canonical DataTable;
- Proposed Actions після post-freeze cleanup використовує canonical OperationalGrid з execute/approval forms;
- Company Home decision queue використовує canonical DataTable;
- production PHTML raw-table whitelist не має product-level exceptions і містить лише canonical renderers;
- PHASE 13 architecture gate запускається у CI;
- Property PDF лишається service-level print renderer без raw HTML tables;
- винятки класифіковані явно, а не залишені випадково.
