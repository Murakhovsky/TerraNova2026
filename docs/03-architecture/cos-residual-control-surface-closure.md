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

## Наступні хвилі

- Wave 2: COS Control Center read-only runtime tables;
- Wave 3: residual audit та classification винятків.

## Винятки

### Редагована таблиця користувачів

`admin/users.phtml` містить inline user mutations і form ownership на рівні рядка. Це не read-only DataTable і лишається explicit editable-grid boundary.

### Операційні дії COS

У COS Control Center секції з execute/approval forms можуть залишатися specialized operational grids навіть після канонізації read-only runtime tables.

## Критерії завершення

- Client Case Show не містить raw `tn-listing-table` для inbound relations;
- property deep links збережені;
- PHASE 12 workflow/mutation guards лишаються intact;
- PHASE 13 architecture gate запускається у CI;
- винятки класифіковані явно, а не залишені випадково.
